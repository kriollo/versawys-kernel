<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use versaWYS\kernel\Profiler;
use versaWYS\kernel\Request;

final class ProfilerTest extends TestCase
{
    private string $dir;

    private mixed $originalConfig = null;

    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<string, mixed> */
    private array $get = [];

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $this->server = $_SERVER;
        $this->get = $_GET;
        $this->dir = sys_get_temp_dir() . '/versawys_profiler_' . bin2hex(random_bytes(6));
        $config = [
            'build' => ['debug' => true],
            'profiler' => ['dir' => $this->dir],
            'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/'],
        ];
        Profiler::reset();
    }

    protected function tearDown(): void
    {
        global $config;

        Profiler::reset();
        $config = $this->originalConfig;
        $_SERVER = $this->server;
        $_GET = $this->get;
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testRegistraTiemposConsultasYExcepcionesSinGuardarDatosSensibles(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/usuarios?token=abc123secreto&page=2';
        // @mago-expect lint:no-literal-password
        $_GET = ['token' => 'abc123secreto', 'page' => '2'];

        Profiler::begin();
        Profiler::span('middleware', 'GlobalMiddleware::checkSession', 1.5);
        Profiler::query(
            'SELECT * FROM versa_users WHERE email = ? AND password = ?',
            ['ana@empresa.cl', 'Clave#Secreta'],
            2.25,
        );
        Profiler::span('controller', 'app\controllers\UsersController::index', 4.0);
        Profiler::exception(new RuntimeException('falló algo'));
        Profiler::cache('hits');
        Profiler::job('app\jobs\EnviarBienvenida', 'default');
        $data = Profiler::finish();

        self::assertNotNull($data);
        self::assertSame('/admin/usuarios', $data['path']);
        self::assertSame(['token', 'page'], $data['query_keys']);
        self::assertSame('app\controllers\UsersController::index', $data['controller']);
        self::assertSame(2, $data['queries'][0]['params']);
        self::assertCount(1, $data['exceptions']);
        self::assertSame(1, $data['cache']['hits']);

        // Lo que queda en disco no tiene valores sensibles: ni los bindings ni los de la URL
        $stored = (string) file_get_contents($this->dir . '/' . $data['id'] . '.json');
        foreach (['ana@empresa.cl', 'Clave#Secreta', 'abc123secreto'] as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }
    }

    public function testDetectaLaMismaConsultaRepetidaComoPosibleNMasUno(): void
    {
        $queries = [];
        for ($i = 0; $i < Profiler::N_PLUS_ONE; $i++) {
            $queries[] = ['sql' => 'SELECT * FROM tareas WHERE id_tablero = ?'];
        }

        $queries[] = ['sql' => 'SELECT * FROM tableros'];

        self::assertSame(
            ['SELECT * FROM tareas WHERE id_tablero = ?' => Profiler::N_PLUS_ONE],
            Profiler::nPlusOne($queries),
        );
        self::assertSame([], Profiler::nPlusOne(array_slice($queries, 1)));
    }

    public function testLaBarraSeAgregaAntesDelCierreDelBodySoloEnHtml(): void
    {
        $summary = [
            'id' => 'abc',
            'status' => 200,
            'ms' => 12.5,
            'queries' => 3,
            'db_ms' => 1.2,
            'n_plus_one' => 1,
            'memory_mb' => 4.0,
            'controller' => 'app\controllers\X::y',
            'exceptions' => 0,
        ];

        $html = Profiler::injectToolbar('<html><body><p>hola</p></body></html>', $summary);
        self::assertMatchesRegularExpression(
            '#<p>hola</p><style nonce="[^"]+">.*</style><div id="versa-profiler">.*</div></body>#s',
            $html,
        );
        self::assertStringContainsString('/_profiler/abc', $html);
        self::assertStringContainsString('N+1', $html);

        self::assertSame('{"ok":1}', Profiler::injectToolbar('{"ok":1}', $summary), 'JSON queda intacto');
        self::assertSame('<body></body>', Profiler::injectToolbar('<body></body>', null), 'sin registro no hay barra');
    }

    public function testElPanelSoloRespondeDesdeLaPropiaMaquinaYConDebug(): void
    {
        global $config;

        self::assertTrue(Profiler::allowed('127.0.0.1'));
        self::assertTrue(Profiler::allowed('::1'));
        self::assertFalse(Profiler::allowed('203.0.113.5'));

        $config['profiler']['allowed_ips'] = ['203.0.113.5'];
        self::assertTrue(Profiler::allowed('203.0.113.5'));

        $config['build']['debug'] = false;
        self::assertFalse(Profiler::allowed('127.0.0.1'), 'sin debug no hay panel para nadie');
    }

    public function testElIdDelPanelNoPermiteSalirDeSuCarpeta(): void
    {
        self::assertStringContainsString('No existe el registro', Profiler::panel('../../config/config'));
    }

    public function testGuardaSoloLasUltimasCienPeticiones(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        for ($i = 0; $i < 105; $i++) {
            Request::resetRequestState(); // un id nuevo por petición
            Profiler::begin();
            Profiler::finish();
        }

        $files = glob($this->dir . '/*.json');
        self::assertIsArray($files);
        self::assertCount(100, $files);
    }
}
