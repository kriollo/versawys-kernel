<?php

declare(strict_types=1);

namespace versaWYS\kernel\Testing;

use PDO;
use RuntimeException;
use versaWYS\kernel\cli\ConfigManager;
use versaWYS\kernel\helpers\Functions;

/**
 * Base de datos SQLite desechable para las pruebas, con las migraciones y los seeders del proyecto.
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

            if (($config['database']['driver'] ?? '') !== 'sqlite') {
                throw new RuntimeException('TestDatabase necesita que las pruebas usen SQLite');
            }

            self::$database = (string) $config['database']['database'];

            return TestClient::boot($root);
        }

        $dir = sys_get_temp_dir() . '/versawys_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new RuntimeException("No se pudo crear {$dir}");
        }

        $config = ConfigManager::defaults();
        $config['database'] =
            ['driver' => 'sqlite', 'database' => $dir . '/test.sqlite', 'log_path' => $dir . '/orm/']
            + $config['database'];
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

        self::$database = $dir . '/test.sqlite';
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

        return new PDO('sqlite:' . self::$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** Una empresa nueva; devuelve su id. La empresa 1 la crean los seeders. */
    public static function empresa(string $nombre = 'Otra empresa'): int
    {
        $pdo = self::pdo();
        $pdo->prepare(
            'INSERT INTO empresas (token_empresa, rut, nombre, email, estado) VALUES (?, ?, ?, ?, 1)',
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
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)')
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
