<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use versaWYS\kernel\cli\ServerManager;

/**
 * Servidor de desarrollo (php versaCLI serve): audio y video con rangos (routerServe.php) y varios procesos
 * (PHP_CLI_SERVER_WORKERS). Sin rangos el navegador no puede saltar dentro de un video; con un solo proceso,
 * una descarga larga deja en fila a las demás peticiones.
 */
final class DevServerTest extends TestCase
{
    private string $dir;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private string $media = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/versawys-serve-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public', 0o777, true);
        file_put_contents($this->dir . '/index.php', '<?php echo "app";');
        $this->media = random_bytes(100_000);
        file_put_contents($this->dir . '/public/a.wav', $this->media);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        foreach (['public/a.wav', 'public/fuera.wav', 'index.php'] as $file) {
            if (is_link($this->dir . '/' . $file) || file_exists($this->dir . '/' . $file)) {
                unlink($this->dir . '/' . $file);
            }
        }

        rmdir($this->dir . '/public');
        rmdir($this->dir);
        putenv('PHP_CLI_SERVER_WORKERS');
    }

    public function testSirveAudioYVideoConRangos(): void
    {
        $this->arrancar();

        [$status, $headers, $body] = $this->get('/public/a.wav', 'bytes=100-199');
        $this->assertSame(206, $status);
        $this->assertSame('bytes 100-199/100000', $headers['content-range']);
        $this->assertSame('bytes', $headers['accept-ranges']);
        $this->assertSame(substr($this->media, 100, 100), $body);

        [$status, $headers, $body] = $this->get('/public/a.wav', 'bytes=-10');
        $this->assertSame(206, $status);
        $this->assertSame('bytes 99990-99999/100000', $headers['content-range']);
        $this->assertSame(substr($this->media, -10), $body);

        [$status, $headers, $body] = $this->get('/public/a.wav', 'bytes=99990-');
        $this->assertSame(206, $status);
        $this->assertSame(substr($this->media, 99_990), $body);

        [$status, $headers, $body] = $this->get('/public/a.wav');
        $this->assertSame(200, $status);
        $this->assertSame('bytes', $headers['accept-ranges']);
        $this->assertSame($this->media, $body);

        foreach (['bytes=200000-', 'bytes=abc', 'bytes=-'] as $rango) {
            [$status, $headers] = $this->get('/public/a.wav', $rango);
            $this->assertSame(416, $status, $rango);
            $this->assertSame('bytes */100000', $headers['content-range'], $rango);
        }
    }

    public function testNoSirveArchivosFueraDelProyecto(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Sin enlaces simbólicos en Windows');
        }

        // Un enlace simbólico que apunta afuera del proyecto
        $afuera = tempnam(sys_get_temp_dir(), 'versawys-afuera');
        file_put_contents($afuera, 'secreto');
        symlink($afuera, $this->dir . '/public/fuera.wav');
        $this->arrancar();

        [$status, , $body] = $this->get('/public/fuera.wav');
        unlink($afuera);
        $this->assertSame(404, $status);
        $this->assertStringNotContainsString('secreto', $body);
    }

    public function testCuantosProcesosAtiendenPeticiones(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('PHP no soporta PHP_CLI_SERVER_WORKERS en Windows');
        }

        $workers = new ReflectionMethod(ServerManager::class, 'serverWorkers');

        $this->assertSame(4, $workers->invoke(null, 4), '--workers=4');

        putenv('PHP_CLI_SERVER_WORKERS=6');
        $this->assertSame(6, $workers->invoke(null, null), 'PHP_CLI_SERVER_WORKERS definido');

        putenv('PHP_CLI_SERVER_WORKERS');
        $porDefecto = $workers->invoke(null, null);
        $this->assertGreaterThanOrEqual(2, $porDefecto);
        $this->assertLessThanOrEqual(8, $porDefecto);
    }

    private function arrancar(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(
            (string) stream_socket_get_name($socket, false),
            strrpos((string) stream_socket_get_name($socket, false), ':') + 1,
        );
        fclose($socket);

        $router = dirname(__DIR__) . '/src/cli/routerServe.php';
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', $this->dir, $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $this->dir,
        );

        // Mientras arranca, conectar falla con un aviso: se ignora hasta que responda
        set_error_handler(static fn(): bool => true);
        try {
            for ($i = 0; $i < 50; $i++) {
                $conexion = fsockopen('127.0.0.1', $this->port);
                if ($conexion !== false) {
                    fclose($conexion);

                    return;
                }

                usleep(100_000);
            }
        } finally {
            restore_error_handler();
        }

        $this->fail('El servidor de desarrollo no arrancó');
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function get(string $uri, ?string $range = null): array
    {
        $context = stream_context_create(['http' => [
            'header' => $range !== null ? "Range: {$range}\r\n" : '',
            'ignore_errors' => true,
        ]]);
        $body = (string) file_get_contents("http://127.0.0.1:{$this->port}{$uri}", false, $context);
        $headers = [];
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$nombre, $valor] = explode(':', $line, 2);
                $headers[strtolower(trim($nombre))] = trim($valor);
            }
        }

        return [$status, $headers, $body];
    }
}
