<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\helpers\Functions;

/** El cliente HTTP de Functions (httpGet, httpPostJson, httpRequest, httpUploadFile) contra un servidor local. */
final class HttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $port = random_int(18_000, 18_999);
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/Fixtures/eco-http.php'],
            [
                ['pipe', 'r'],
                ['file', '/dev/null', 'w'],
                ['file', '/dev/null', 'w'],
            ],
            $pipes,
        );
        self::$base = "http://127.0.0.1:{$port}";
        // Esperar a que el servidor escuche: mientras no lo haga, fsockopen falla (es lo esperado)
        // @mago-expect lint:no-error-control-operator
        for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testGetConQueryCabecerasYRespuestaJson(): void
    {
        $r = Functions::httpGet(self::$base . '/eco?x=1&amp;y=2', ['X-Token: abc'], ['cookies' => 'sesion=1']);

        self::assertTrue($r['success']);
        self::assertSame(200, $r['status_code']);
        self::assertSame('GET', $r['body']['method']);
        self::assertSame(['x' => '1', 'y' => '2'], $r['body']['query'], 'las entidades HTML de la URL se decodifican');
        self::assertSame('abc', $r['body']['token']);
        self::assertSame('sesion=1', $r['body']['cookie']);
        self::assertNotSame('', $r['body']['user_agent']);
    }

    public function testPostJson(): void
    {
        $r = Functions::httpPostJson(self::$base . '/eco', ['nombre' => 'Ana', 'n' => 2]);

        self::assertSame('POST', $r['body']['method']);
        self::assertStringStartsWith('application/json', $r['body']['content_type']);
        self::assertSame(['nombre' => 'Ana', 'n' => 2], json_decode($r['body']['body'], true));
    }

    public function testFormularioYMetodosConCuerpo(): void
    {
        $r = Functions::httpRequest(self::$base . '/eco', 'PUT', [], ['a' => '1', 'b' => 'dos']);
        self::assertSame('PUT', $r['body']['method']);
        self::assertSame('a=1&b=dos', $r['body']['body']);

        $r = Functions::httpRequest(self::$base . '/eco', 'DELETE', [], '{"id":3}');
        self::assertSame('DELETE', $r['body']['method']);
        self::assertSame('{"id":3}', $r['body']['body']);
    }

    public function testSubirUnArchivo(): void
    {
        $archivo = tempnam(sys_get_temp_dir(), 'subida');
        file_put_contents($archivo, 'contenido');

        $r = Functions::httpUploadFile(
            self::$base . '/eco',
            $archivo,
            'documento',
            ['nota' => 'x'],
            clientFileName: 'informe.txt',
        );
        unlink($archivo);

        self::assertSame(['documento' => ['name' => 'informe.txt', 'size' => 9]], $r['body']['files']);
        self::assertSame(['nota' => 'x'], $r['body']['post']);

        $faltante = Functions::httpUploadFile(self::$base . '/eco', '/no/existe.txt');
        self::assertFalse($faltante['success']);
        self::assertStringContainsString('Archivo no encontrado', $faltante['error']);
    }

    public function testTextoCabecerasErroresYRedirecciones(): void
    {
        $texto = Functions::httpGet(self::$base . '/texto');
        self::assertSame('hola mundo', $texto['body']);
        self::assertSame('si', $texto['headers']['X-Prueba'] ?? null);

        $error = Functions::httpGet(self::$base . '/error');
        self::assertFalse($error['success']);
        self::assertSame(503, $error['status_code']);
        self::assertSame(['success' => 0], $error['body']);

        $crudo = Functions::httpGet(self::$base . '/error', [], ['raw_output' => true]);
        self::assertSame('{"success":0}', $crudo['body'], 'raw_output no decodifica el JSON');

        self::assertSame('hola mundo', Functions::httpGet(self::$base . '/redirige')['body']);
        self::assertSame(
            302,
            Functions::httpGet(self::$base . '/redirige', [], ['follow_redirects' => false])['status_code'],
        );
    }

    public function testSinServidorDevuelveElErrorSinLanzar(): void
    {
        $r = Functions::httpGet('http://127.0.0.1:1/');

        self::assertFalse($r['success']);
        self::assertSame(0, $r['status_code']);
        self::assertNotSame(0, $r['errno']);
        self::assertNotSame('', $r['error']);
    }
}
