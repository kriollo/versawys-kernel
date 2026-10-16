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

    public function testGuardaLaPeticionYLaRespuestaParaRepetirlaSinSecretos(): void
    {
        // @mago-expect lint:no-literal-password
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/clientes?page=2&api_key=k-secreta',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_COOKIE' => 'PHPSESSID=sesion-secreta',
            'HTTP_AUTHORIZATION' => 'Bearer jwt-secreto',
            'HTTP_X_CSRF_TOKEN' => 'csrf-secreto',
            'HTTP_X_CLIENTE' => 'app-movil',
            'HTTP_HOST' => 'localhost',
            'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_REFERER' => 'http://localhost/admin',
            'HTTP_USER_AGENT' => 'Chrome',
        ];
        // @mago-expect lint:no-literal-password
        $_GET = ['page' => '2', 'api_key' => 'k-secreta'];
        // @mago-expect lint:no-literal-password
        Request::setInput('{"nombre":"Ana","password":"Clave-secreta","datos":{"token":"t-secreto"}}');
        $GLOBALS['request'] = new Request();

        Profiler::begin();
        $data = Profiler::finish();
        Profiler::attachResponse('{"success":1,"access_token":"r-secreto","id":7}', [
            'Content-Type: application/json',
            'Set-Cookie: tknHash=jwt-de-respuesta; HttpOnly',
        ]);
        Request::setInput(null);
        unset($GLOBALS['request']);

        $stored = (string) file_get_contents($this->dir . '/' . $data['id'] . '.json');
        foreach ([
            'sesion-secreta',
            'jwt-secreto',
            'csrf-secreto',
            'Clave-secreta',
            't-secreto',
            'k-secreta',
            'r-secreto',
            'jwt-de-respuesta',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $stored, "{$secret} no debe quedar en disco");
        }

        $replay = Profiler::replayFor((string) $data['id']);
        self::assertNotNull($replay);
        self::assertSame('POST', $replay['method']);
        self::assertSame('/admin/clientes?page=2&api_key=%5Boculto%5D', $replay['url']);
        self::assertSame(['X-Cliente' => 'app-movil'], $replay['headers'], 'sin las ocultas ni las de la conexión');
        // @mago-expect lint:no-literal-password
        self::assertSame(
            ['nombre' => 'Ana', 'password' => '[oculto]', 'datos' => ['token' => '[oculto]']],
            json_decode($replay['body'], true),
        );

        $detalle = Profiler::panel((string) $data['id']);
        self::assertStringContainsString('Repetir en el probador', $detalle);
        self::assertStringContainsString('/doc?route=all&amp;replay=' . $data['id'], $detalle);
        self::assertStringContainsString('&quot;id&quot;: 7', $detalle, 'el cuerpo de la respuesta');
        self::assertStringContainsString('tknHash=[oculto]', $detalle);
    }

    public function testFormulariosArchivosYCuerposGrandesOBinarios(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/subir',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
        ];
        // @mago-expect lint:no-literal-password
        $_POST = ['titulo' => 'Informe', 'password' => 'no-guardar'];
        $_FILES = ['adjunto' => [
            'name' => 'informe.pdf',
            'type' => 'application/pdf',
            'tmp_name' => '/tmp/x',
            'error' => 0,
            'size' => 1234,
        ]];

        Profiler::begin();
        $data = Profiler::finish();
        $_POST = [];
        $_FILES = [];

        // @mago-expect lint:no-literal-password
        self::assertSame(
            ['titulo' => 'Informe', 'password' => '[oculto]'],
            json_decode($data['request']['body'], true),
        );
        self::assertSame(
            [['field' => 'adjunto', 'name' => 'informe.pdf', 'type' => 'application/pdf', 'size' => 1234]],
            $data['request']['files'],
        );
        self::assertStringContainsString('elegirlos de nuevo', Profiler::panel((string) $data['id']));

        // Un formulario urlencoded queda en su formato, para reenviarlo
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/login',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
        Request::setInput('email=ana%40x.cl&password=secreta');
        $GLOBALS['request'] = new Request();
        Profiler::begin();
        $form = Profiler::finish();
        self::assertSame('email=ana%40x.cl&password=%5Boculto%5D', $form['request']['body']);

        // Un binario no se guarda; un texto enorme, recortado
        Profiler::attachResponse("\x89PNG\x00\xff\xfe", ['Content-Type: image/png']);
        $png = Profiler::replayFor((string) $form['id']);
        self::assertNotNull($png);
        $stored = json_decode((string) file_get_contents($this->dir . '/' . $form['id'] . '.json'), true);
        self::assertStringStartsWith('[contenido binario', $stored['response']['body']);

        Request::setInput(str_repeat('a', 70_000));
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        $GLOBALS['request'] = new Request();
        Profiler::begin();
        $grande = Profiler::finish();
        self::assertStringContainsString('[recortado: 70000 bytes en total]', $grande['request']['body']);
        self::assertLessThan(66_000, strlen($grande['request']['body']));

        Request::setInput(null);
        unset($GLOBALS['request']);
    }

    public function testUnRegistroInexistenteNoSeRepite(): void
    {
        self::assertNull(Profiler::replayFor('no-existe'));
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
            'controller' => '</script><b>x',
            'exceptions' => 0,
        ];

        $html = Profiler::injectToolbar('<html><body><p>hola</p></body></html>', $summary);
        self::assertMatchesRegularExpression(
            '#<p>hola</p><style nonce="[^"]+">.*</style><div id="versa-profiler-spacer"></div><div id="versa-profiler"><span class="vp-label">Modo Debug: Activado</span><span id="vp-metrics"></span>.*</div><script nonce="[^"]+">.*</script></body>#s',
            $html,
        );
        self::assertStringContainsString('"id":"abc"', $html);
        // Un dato que viniera de la aplicación no puede cerrar el script
        self::assertStringNotContainsString('</script><b>x', $html);
        // Se actualiza en cada navegación del SPA
        self::assertStringContainsString('X-Versa-Profiler', $html);

        self::assertSame('{"ok":1}', Profiler::injectToolbar('{"ok":1}', $summary), 'JSON queda intacto');
        self::assertSame('<body></body>', Profiler::injectToolbar('<body></body>', null), 'sin registro no hay barra');
    }

    public function testElScriptDeLaBarraEsJavascriptValido(): void
    {
        exec('command -v node', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('Sin Node.js para revisar la sintaxis');
        }

        $html = Profiler::injectToolbar('<body></body>', [
            'id' => 'a',
            'status' => 200,
            'ms' => 1,
            'queries' => 0,
            'db_ms' => 0,
            'n_plus_one' => 0,
            'memory_mb' => 2,
            'controller' => 'X::y',
            'exceptions' => 0,
        ]);
        self::assertSame(1, preg_match('#<script[^>]*>(.*)</script>#s', $html, $m));
        $file = $this->dir . '.js';
        file_put_contents($file, $m[1]);
        exec('node --check ' . escapeshellarg($file) . ' 2>&1', $errors, $code);
        unlink($file);

        self::assertSame(0, $code, implode("\n", $errors));
    }

    public function testElResumenDeUnRegistroEsLoQueMuestraLaBarra(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/tableros';
        Profiler::begin();
        Profiler::query('SELECT 1', [], 1.0);
        $data = Profiler::finish();

        self::assertNotNull($data);
        $summary = Profiler::summaryFor((string) $data['id']);
        self::assertSame(1, $summary['queries'] ?? null);
        self::assertNull(Profiler::summaryFor('no-existe'));
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
