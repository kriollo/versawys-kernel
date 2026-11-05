<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use app\models\Users;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use VersaORM\SQL\PdoConnection;
use VersaORM\VersaModel;
use VersaORM\VersaORM;
use versaWYS\kernel\CacheUsers;
use versaWYS\kernel\model\OrmFactory;
use versaWYS\kernel\model\VersaORMModel;
use versaWYS\kernel\Session;

require_once __DIR__ . '/Fixtures/app/models/Users.php';
require_once __DIR__ . '/Fixtures/app/models/Empresas.php';
require_once __DIR__ . '/Fixtures/app/models/Dashboard.php';

/**
 * Los datos del usuario se guardan en la sesión y se validan en cada petición con una sola consulta
 * (la huella): cualquier cambio del usuario o de una empresa activa, hecho desde otra sesión, los recarga.
 * Corre en SQLite; CacheUsersPostgresTest repite los mismos casos en PostgreSQL. Si la huella fallara en un
 * motor, la caché no se usaría nunca y nada se rompería a la vista: por eso se prueba en los dos.
 */
class CacheUsersTest extends TestCase
{
    private const string KEY = 'user_data_1_1';

    private string $dir;

    private PDO $pdo;

    private VersaORM $orm;

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        global $config, $session, $versaOrm;

        $this->saved = [
            'config' => $config ?? null,
            'session' => $session ?? null,
            'versaOrm' => $versaOrm ?? null,
            'model_orm' => self::modelOrm()->getValue(),
            'SESSION' => $_SESSION ?? null,
        ];
        $this->dir = sys_get_temp_dir() . '/versawys-cacheusers-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $config = ['build' => ['debug' => false], 'cache' => ['dir' => $this->dir . '/cache']];

        $database = $this->database();
        $this->pdo = $this->connect($database);
        // Los tipos que crea la migración del panel (boolean y timestamps), comunes a los dos motores
        $this->pdo->exec('DROP TABLE IF EXISTS versa_users');
        $this->pdo->exec('DROP TABLE IF EXISTS empresas');
        $this->pdo->exec(
            'CREATE TABLE empresas (id INTEGER PRIMARY KEY, nombre TEXT, estado BOOLEAN, updated_at TIMESTAMP)',
        );
        $this->pdo->exec('CREATE TABLE versa_users (id INTEGER PRIMARY KEY, id_empresa INTEGER, name TEXT, password TEXT,
                restore_token TEXT, role TEXT, id_perfil INTEGER, updated_at TIMESTAMP)');
        $this->pdo->exec("INSERT INTO empresas VALUES (1, 'Uno', TRUE, '2026-01-01 10:00:00'), (2, 'Dos', TRUE, '2026-01-01 10:00:00'),
                (3, 'Tres', FALSE, '2026-01-01 10:00:00')");
        $this->pdo->exec(
            "INSERT INTO versa_users VALUES (1, 1, 'Ana', 'hash-secreto', 'token-secreto', 'user', 7, '2026-01-01 10:00:00')",
        );

        $this->nuevaConexion();

        $_SESSION = ['id_user' => 1, 'empresa_id' => 1];
        $session = (new ReflectionClass(Session::class))->newInstanceWithoutConstructor();
        Users::$loads = 0;
    }

    protected function tearDown(): void
    {
        global $config, $session, $versaOrm;

        // CacheUsersPostgresTest se salta antes de preparar nada
        if (!isset($this->pdo)) {
            return;
        }

        VersaORM::onQuery(null);
        // OrmFactory en producción registra la caché de esquema global: no debe quedar para otras pruebas
        VersaORM::schemaCache(null);
        $this->orm->disconnect();
        $this->pdo->exec('DROP TABLE IF EXISTS versa_users');
        $this->pdo->exec('DROP TABLE IF EXISTS empresas');
        PdoConnection::clearPool();
        $config = $this->saved['config'];
        $session = $this->saved['session'];
        $versaOrm = $this->saved['versaOrm'];
        self::modelOrm()->setValue(null, $this->saved['model_orm']);
        if ($this->saved['versaOrm'] instanceof VersaORM) {
            VersaModel::setORM($this->saved['versaOrm']);
        }

        $_SESSION = $this->saved['SESSION'] ?? [];
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testLaPrimeraPeticionCargaYGuardaSinSecretos(): void
    {
        new CacheUsers();

        self::assertSame(1, Users::$loads);
        $cached = $_SESSION[self::KEY];
        self::assertSame('Ana', $cached['user']['name']);
        self::assertSame('Uno', $cached['empresaSelected']['nombre']);
        self::assertCount(2, $cached['empresas']);
        self::assertSame(['menu-1-7'], $cached['menu_user']);
        self::assertArrayNotHasKey('password', $cached['user']);
        self::assertArrayNotHasKey('restore_token', $cached['user']);
        self::assertIsString($cached['fingerprint']);
    }

    public function testSinCambiosLaSiguientePeticionHaceUnaSolaConsulta(): void
    {
        new CacheUsers();
        $consultas = $this->contarConsultas();

        new CacheUsers();

        self::assertSame(1, Users::$loads, 'usa la caché');
        self::assertSame(1, $consultas->n, 'solo la huella');
    }

    public function testUnCambioDelUsuarioLaRecarga(): void
    {
        new CacheUsers();
        $this->pdo->exec("UPDATE versa_users SET name = 'Ana María', updated_at = '2026-01-01 10:00:01'");

        new CacheUsers();

        self::assertSame(2, Users::$loads);
        self::assertSame('Ana María', $_SESSION[self::KEY]['user']['name']);
    }

    public function testEditarUnaEmpresaActivaLaRecarga(): void
    {
        new CacheUsers();
        $this->pdo->exec("UPDATE empresas SET nombre = 'Uno SpA', updated_at = '2026-01-01 10:00:01' WHERE id = 1");

        new CacheUsers();

        self::assertSame(2, Users::$loads);
        self::assertSame('Uno SpA', $_SESSION[self::KEY]['empresaSelected']['nombre']);
    }

    public function testDesactivarUnaEmpresaLaRecargaAunqueNoCambieUpdatedAt(): void
    {
        new CacheUsers();
        $this->pdo->exec('UPDATE empresas SET estado = FALSE WHERE id = 2');

        new CacheUsers();

        self::assertSame(2, Users::$loads);
        self::assertCount(1, $_SESSION[self::KEY]['empresas']);
    }

    public function testCambiarQueEmpresasEstanActivasLaRecargaConElMismoConteoYFecha(): void
    {
        new CacheUsers();
        // Misma cantidad de activas y mismo último cambio: solo la suma de ids lo distingue
        $this->pdo->exec('UPDATE empresas SET estado = (id <> 2)');

        new CacheUsers();

        self::assertSame(2, Users::$loads);
        self::assertSame(['Uno', 'Tres'], array_column($_SESSION[self::KEY]['empresas'], 'nombre'));
    }

    public function testPasarDeUnaEmpresaActivaADosConLaMismaSumaDeIdsLaRecarga(): void
    {
        $this->pdo->exec('UPDATE empresas SET estado = (id = 3)');
        new CacheUsers();
        // {3} → {1, 2}: misma suma de ids y mismo último cambio; solo el conteo lo distingue
        $this->pdo->exec('UPDATE empresas SET estado = (id <> 3)');

        new CacheUsers();

        self::assertSame(2, Users::$loads);
        self::assertSame(['Uno', 'Dos'], array_column($_SESSION[self::KEY]['empresas'], 'nombre'));
    }

    public function testCambiarElMenuLaRecarga(): void
    {
        new CacheUsers();
        (new CacheUsers())->updateMenuVersion();

        new CacheUsers();

        self::assertSame(2, Users::$loads);
    }

    public function testVencidosLosCincoMinutosLaRecarga(): void
    {
        new CacheUsers();
        $_SESSION[self::KEY]['ttl'] = time() - 1;

        new CacheUsers();

        self::assertSame(2, Users::$loads);
        self::assertGreaterThan(time(), $_SESSION[self::KEY]['ttl']);
    }

    public function testUnUsuarioBorradoNuncaUsaLaCache(): void
    {
        new CacheUsers();
        $this->pdo->exec('DELETE FROM versa_users WHERE id = 1');

        new CacheUsers();
        new CacheUsers();

        self::assertSame(3, Users::$loads, 'sin huella, cada petición vuelve a la base');
    }

    public function testSiLaHuellaFallaNoSeUsaLaCache(): void
    {
        new CacheUsers();
        // La consulta de la huella necesita updated_at en empresas; los datos no
        $this->pdo->exec('ALTER TABLE empresas DROP COLUMN updated_at');
        // Otra petición, otra conexión: PostgreSQL no reutiliza un plan preparado con el esquema anterior
        $this->nuevaConexion();

        new CacheUsers();

        self::assertSame(2, Users::$loads);
        self::assertNull($_SESSION[self::KEY]['fingerprint']);
    }

    public function testCadaEmpresaDeLaSesionTieneSuPropiaCache(): void
    {
        new CacheUsers();
        $_SESSION['empresa_id'] = 2;

        new CacheUsers();

        self::assertSame(2, Users::$loads);
        self::assertSame('Uno', $_SESSION['user_data_1_1']['empresaSelected']['nombre']);
        self::assertSame('Dos', $_SESSION['user_data_1_2']['empresaSelected']['nombre']);
    }

    /** La carpeta temporal de esta prueba (caché y logs del ORM) */
    protected function dir(): string
    {
        return $this->dir;
    }

    /** @return array<string, mixed> configuración de `database` para OrmFactory */
    protected function database(): array
    {
        return [
            'engine' => 'pdo',
            'driver' => 'sqlite',
            'database' => $this->dir . '/app.sqlite',
            'log_path' => $this->dir . '/orm/',
            'host' => '',
            'port' => 0,
            'charset' => 'utf8',
            'collation' => '',
            'username' => '',
            'password' => '',
        ];
    }

    /** @param array<string, mixed> $database */
    protected function connect(array $database): PDO
    {
        return new PDO('sqlite:' . $database['database'], options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** Una conexión nueva del ORM, como en cada petición, y los modelos apuntando a ella */
    private function nuevaConexion(): void
    {
        global $versaOrm;

        if (isset($this->orm)) {
            $this->orm->disconnect();
            PdoConnection::clearPool();
        }

        $this->orm = OrmFactory::make([
            'build' => ['debug' => false, 'timezone' => 'America/Santiago'],
            'database' => $this->database(),
        ]);
        $versaOrm = $this->orm;
        VersaModel::setORM($this->orm);
        self::modelOrm()->setValue(null, $this->orm);
    }

    private static function modelOrm(): ReflectionProperty
    {
        return new ReflectionProperty(VersaORMModel::class, 'orm');
    }

    private function contarConsultas(): stdClass
    {
        $contador = new stdClass();
        $contador->n = 0;
        VersaORM::onQuery(static function () use ($contador): void {
            $contador->n++;
        });

        return $contador;
    }
}
