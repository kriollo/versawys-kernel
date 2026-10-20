<?php

declare(strict_types=1);

namespace versaWYS\kernel\Testing;

use PDO;
use RuntimeException;
use versaWYS\kernel\cli\ConfigManager;
use versaWYS\kernel\helpers\Functions;

/**
 * Base de datos desechable para las pruebas, con las migraciones y los seeders del proyecto. Es SQLite,
 * salvo que VERSA_TEST_DB indique un PostgreSQL desechable (`host:puerto:base:usuario:clave`): entonces
 * se vacía su esquema `public` y se migra ahí. Sirve para los proyectos cuyas migraciones usan lo que
 * SQLite no tiene (modificar columnas, SQL propio de PostgreSQL), y prueba contra el motor de producción.
 * Es una sola por proceso (TestClient arranca una vez): cada prueba crea sus propios usuarios y
 * empresas, con correos únicos, y no depende de lo que hagan las demás.
 *
 *     beforeAll(fn() => TestDatabase::boot());
 *
 *     it('lista los productos de la empresa', function (): void {
 *         $admin = TestDatabase::login(TestDatabase::user());
 *         $admin->getJson('/admin/productos/api/productos')->assertOk();
 *     });
 */
final class TestDatabase
{
    /** La contraseña de todos los usuarios de prueba (solo existen en la base desechable) */
    // @mago-expect lint:no-literal-password
    public const string PASSWORD = 'Clave#Segura2026';

    /** El archivo SQLite que usa la aplicación en las pruebas */
    private static ?string $database = null;

    /** @var array{host: string, port: string, database: string, username: string, password: string}|null */
    private static ?array $postgres = null;

    /** Crea la base (la primera vez) y arranca TestClient con ella. */
    public static function boot(): TestClient
    {
        $root = (string) getcwd();
        if (self::$database !== null) {
            return TestClient::boot($root);
        }

        // Si otra prueba ya arrancó la aplicación (con su propia base), se usa esa: TestClient arranca
        // una sola vez por proceso y los usuarios tienen que quedar en la base que la aplicación lee
        if (TestClient::booted()) {
            global $config;

            $driver = (string) ($config['database']['driver'] ?? '');
            if ($driver === 'sqlite') {
                self::$database = (string) $config['database']['database'];
            } elseif (str_contains($driver, 'postgres') && self::postgresFromEnv() !== null) {
                self::$postgres = self::postgresFromEnv();
                self::$database = self::$postgres['database'];
            } else {
                throw new RuntimeException(
                    'TestDatabase necesita que las pruebas usen SQLite o el PostgreSQL de VERSA_TEST_DB',
                );
            }

            return TestClient::boot($root);
        }

        $dir = sys_get_temp_dir() . '/versawys_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new RuntimeException("No se pudo crear {$dir}");
        }

        $config = ConfigManager::defaults();
        $postgres = self::postgresFromEnv();
        if ($postgres !== null) {
            // Desechable: se vacía entera, también si una ejecución anterior se cortó a medias
            self::connect($postgres)->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public');
            $config['database'] =
                ['driver' => 'postgresql', 'log_path' => $dir . '/orm/'] + $postgres + $config['database'];
        } else {
            $config['database'] =
                ['driver' => 'sqlite', 'database' => $dir . '/test.sqlite', 'log_path' => $dir . '/orm/']
                + $config['database'];
        }
        $config['session']['key'] = bin2hex(random_bytes(32));
        // Igual que una instalación nueva (config:init): CSP con nonce y sin eval
        $config['csp']['nonce'] = true;
        $config['csp']['unsafe_eval'] = false;
        $config['twig']['compiled_dir'] = $dir . '/twig';
        $config['logging']['dir'] = $dir . '/logs/';
        $config['rate_limiting']['cache_dir'] = $dir . '/rate_limits/';
        // Las pruebas inician muchas sesiones seguidas: el rate limiting no las puede frenar
        foreach (['global', 'api', 'upload', 'sensitive', 'login'] as $rule) {
            $config['rate_limiting']['rules'][$rule]['requests'] = 100_000;
        }

        file_put_contents($dir . '/config.json', json_encode($config));

        $env = 'VERSAWYS_CONFIG=' . escapeshellarg($dir . '/config.json');
        $php = escapeshellarg(PHP_BINARY);
        exec(
            "{$env} {$php} versaCLI migrate:up 2>&1 < /dev/null && {$env} {$php} versaCLI seeder:runAll 2>&1 < /dev/null",
            $output,
            $code,
        );
        if ($code !== 0) {
            throw new RuntimeException("No se pudo preparar la base de prueba:\n" . implode("\n", $output));
        }

        self::$postgres = $postgres;
        self::$database = $postgres['database'] ?? $dir . '/test.sqlite';
        register_shutdown_function(static fn(): string|false => exec('rm -rf ' . escapeshellarg($dir)));

        return TestClient::boot($root, $dir . '/config.json');
    }

    /** El config.json de las pruebas: para correr versaCLI contra la misma base (VERSAWYS_CONFIG). */
    public static function configPath(): string
    {
        self::boot();

        return (string) getenv('VERSAWYS_CONFIG');
    }

    /** Conexión directa, para preparar datos o revisar lo que quedó guardado. */
    public static function pdo(): PDO
    {
        if (self::$database === null) {
            throw new RuntimeException('Llama primero a TestDatabase::boot()');
        }

        if (self::$postgres !== null) {
            return self::connect(self::$postgres);
        }

        return new PDO('sqlite:' . self::$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array{host: string, port: string, database: string, username: string, password: string}|null */
    private static function postgresFromEnv(): ?array
    {
        $value = getenv('VERSA_TEST_DB');
        if (!is_string($value) || substr_count($value, ':') !== 4) {
            return null;
        }

        [$host, $port, $database, $username, $password] = explode(':', $value);

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ];
    }

    /** @param array{host: string, port: string, database: string, username: string, password: string} $db */
    private static function connect(array $db): PDO
    {
        return new PDO(
            "pgsql:host={$db['host']};port={$db['port']};dbname={$db['database']}",
            $db['username'],
            $db['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        );
    }

    /** Una empresa nueva; devuelve su id. La empresa 1 la crean los seeders. */
    public static function empresa(string $nombre = 'Otra empresa'): int
    {
        $pdo = self::pdo();
        $pdo->prepare(
            'INSERT INTO empresas (token_empresa, rut, nombre, email, estado) VALUES (?, ?, ?, ?, TRUE)',
        )->execute([bin2hex(random_bytes(16)), self::rut(), $nombre, self::unique('empresa')]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Un usuario activo con la contraseña PASSWORD; devuelve su correo (único).
     *
     * @param 'admin'|'superadmin'|'user' $role
     */
    public static function user(string $role = 'admin', int $empresa = 1): string
    {
        $email = self::unique($role);
        self::pdo()
            ->prepare('INSERT INTO versa_users (id_empresa, tokenid, name, email, password, expiration_pass, role, status, id_perfil)
                 VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, ?)')
            ->execute([
                $empresa,
                bin2hex(random_bytes(16)),
                ucfirst($role) . ' de prueba',
                $email,
                Functions::hash(self::PASSWORD),
                date('Y-m-d', strtotime('+90 days')),
                $role,
                $role === 'user' ? 2 : 1,
            ]);

        return $email;
    }

    /** Un cliente con la sesión de ese usuario iniciada por el login real del panel. */
    public static function login(string $email): TestClient
    {
        $client = self::boot();
        $client->login($email, self::PASSWORD);

        return $client;
    }

    /** Un RUT válido distinto en cada llamada (es único en la tabla empresas) */
    private static function rut(): string
    {
        $body = random_int(10_000_000, 99_999_999);
        $sum = 0;
        $factor = 2;
        for ($digits = $body; $digits > 0; $digits = intdiv($digits, 10)) {
            $sum += ($digits % 10) * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $dv = 11 - ($sum % 11);

        return $body . '-' . match ($dv) {
            11 => '0',
            10 => 'K',
            default => (string) $dv,
        };
    }

    private static function unique(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4)) . '@prueba.test';
    }
}
