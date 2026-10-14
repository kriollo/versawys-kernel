<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Request;
use versaWYS\kernel\Router;

final class RouterTest extends TestCase
{
    private array $originalServer;

    private bool $hadRequest;

    private mixed $originalRequest;

    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalServer = $_SERVER;
        $this->hadRequest = array_key_exists('request', $GLOBALS);
        $this->originalRequest = $GLOBALS['request'] ?? null;
        $this->originalConfig = $config ?? null;

        $config = [
            'trusted_proxies' => [],
            'rate_limiting' => ['enabled' => false],
            'admin' => [],
            'build' => ['debug' => false],
        ];
    }

    protected function tearDown(): void
    {
        global $config;

        $_SERVER = $this->originalServer;
        $config = $this->originalConfig;
        if ($this->hadRequest) {
            $GLOBALS['request'] = $this->originalRequest;
        } else {
            unset($GLOBALS['request']);
        }
    }

    public function testItDispatchesAnExactGetRouteToItsController(): void
    {
        global $request;

        $path = '/kernel-test-' . bin2hex(random_bytes(4));
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $request = new Request();
        Router::get($path, [RouterTestController::class, 'show']);

        ob_start();

        try {
            $result = (new Router())->resolve();
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('ok', $output);
        self::assertTrue($result);
    }

    public function testControllerCodeBecomesTheHttpStatus(): void
    {
        // Desde el kernel empaquetado, ['code' => 401] responde HTTP 401 (antes siempre 200).
        // Los frontends que tratan 401 como sesión vencida dependen de esto.
        $path = $this->route('get', '/', [RouterTestController::class, 'unauthorized']);

        $output = $this->dispatch('GET', $path);

        self::assertSame(401, http_response_code());
        self::assertSame(['success' => 0, 'message' => 'No autenticado.', 'code' => 401], json_decode($output, true));
    }

    public function testACodeThatIsNotAnHttpStatusFallsBackTo200(): void
    {
        // Hay controladores que devuelven el código de una excepción (p. ej. 23000 de PDO)
        $path = $this->route('get', '/', [RouterTestController::class, 'pdoCode']);

        $this->dispatch('GET', $path);

        self::assertSame(200, http_response_code());
    }

    public function testAMiddlewareResponseStopsTheRequestWithItsCode(): void
    {
        $path = $this->route(
            'get',
            '/',
            [RouterTestController::class, 'show'],
            [[RouterTestMiddleware::class, 'deny']],
        );

        $output = $this->dispatch('GET', $path);

        self::assertSame(403, http_response_code());
        self::assertStringNotContainsString('ok', $output);
    }

    public function testRouteParametersReachTheController(): void
    {
        $path = $this->route('get', '/{id}', [RouterTestController::class, 'echo']);

        self::assertSame('id=42', $this->dispatch('GET', str_replace('{id}', '42', $path)));
    }

    public function testHeadUsesTheGetRouteWithoutBody(): void
    {
        $path = $this->route('get', '/', [RouterTestController::class, 'unauthorized']);

        self::assertSame('', $this->dispatch('HEAD', $path));
        self::assertSame(401, http_response_code());
    }

    public function testAnUnknownMethodGets405(): void
    {
        $result = $this->dispatchIsolated(
            'TRACE',
            '/kernel-test-405',
            "Router::get('/kernel-test-405', [RouterTestController::class, 'show']);",
        );

        self::assertSame(405, $result['status']);
        self::assertStringNotContainsString('ok', $result['body']);
    }

    public function testGroupPrefixAndMiddlewareApplyToEveryRoute(): void
    {
        $prefix = '/kernel-test-' . bin2hex(random_bytes(4));
        Router::group(
            $prefix,
            static function (): void {
                Router::get('/a', [RouterTestController::class, 'show']);
            },
            [[RouterTestMiddleware::class, 'deny']],
        );

        $this->dispatch('GET', $prefix . '/a');

        self::assertSame(403, http_response_code());
    }

    public function testExtraSlashesReachTheSameRoute(): void
    {
        $path = $this->route('get', '/x', [RouterTestController::class, 'show']);

        self::assertSame('ok', $this->dispatch('GET', $path . '/'));
        self::assertSame('ok', $this->dispatch('GET', str_replace('/x', '//x', $path)));
    }

    public function testAPostCanBecomeQueryThroughTheOverrideHeader(): void
    {
        // php -S responde 501 a QUERY: en desarrollo se envía como POST con el override
        $path = $this->route('query', '/', [RouterTestController::class, 'show']);

        self::assertSame('ok', $this->dispatch('POST', $path, ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'QUERY']));
    }

    public function testTheOverrideNeverTurnsAGetIntoAnotherMethod(): void
    {
        // Un enlace o una imagen (GET desde otro sitio) no puede alcanzar una ruta que modifica datos
        $result = $this->dispatchIsolated(
            'GET',
            '/kernel-test-borrar',
            "Router::delete('/kernel-test-borrar', [RouterTestController::class, 'show']);",
            ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE'],
        );

        self::assertStringNotContainsString('ok', $result['body']);
        self::assertContains($result['status'], [404, 405]);
    }

    public function testTheCustomPanelUrlOnlyReplacesThePrefix(): void
    {
        global $config;
        $config['admin'] = ['custom_url' => 'panel', 'hide_default' => true];
        $id = bin2hex(random_bytes(4));
        Router::get("/admin/kernel-test-{$id}/panel", [RouterTestController::class, 'show']);

        self::assertSame('ok', $this->dispatch('GET', "/panel/kernel-test-{$id}/panel"));
    }

    public function testTheDefaultPanelUrlIsHiddenWhenThereIsACustomOne(): void
    {
        global $config;
        $config['admin'] = ['custom_url' => 'panel', 'hide_default' => true];
        $id = bin2hex(random_bytes(4));
        Router::get("/admin/kernel-test-{$id}", [RouterTestController::class, 'show']);

        $output = $this->dispatch('GET', "/admin/kernel-test-{$id}", ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertSame(404, http_response_code());
        self::assertStringNotContainsString('ok', $output);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function trickyPaths(): iterable
    {
        yield 'punto-punto codificado' => ['/kernel-test-otra/%2e%2e/kernel-test-destino'];
        yield 'punto-punto en mayúsculas' => ['/kernel-test-otra/%2E%2E/kernel-test-destino'];
        yield 'barra codificada' => ['/kernel-test-otra%2fkernel-test-destino'];
        yield 'byte nulo' => ['/kernel-test-destino%00.css'];
        yield 'barra invertida' => ['/kernel-test-otra\\..\\kernel-test-destino'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('trickyPaths')]
    public function testEncodedTraversalNeverReachesAnotherRoute(string $path): void
    {
        Router::get('/kernel-test-destino', [RouterTestController::class, 'show']);

        $output = $this->dispatch('GET', $path, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertStringNotContainsString('ok', $output);
        self::assertSame(404, http_response_code());
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function clients(): iterable
    {
        yield 'API' => [['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']];
        yield 'navegador' => [['HTTP_ACCEPT' => 'text/html']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clients')]
    public function testAnUncaughtExceptionAnswers500WithoutItsDetails(array $client): void
    {
        $result = $this->dispatchIsolated(
            'GET',
            '/kernel-test-falla',
            "Router::get('/kernel-test-falla', [RouterTestController::class, 'explode']);",
            $client,
        );

        self::assertSame(500, $result['status']);
        self::assertStringNotContainsString('SELECT', $result['body']);
        self::assertStringNotContainsString('/var/www', $result['body']);
    }

    public function testAnUnknownApiRouteGetsA404WithoutInternals(): void
    {
        $output = $this->dispatch('GET', '/kernel-test-nada-' . bin2hex(random_bytes(4)), [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertSame(404, http_response_code());
        self::assertIsArray(json_decode($output, true));
        self::assertStringNotContainsString('.php', $output);
    }

    /**
     * Para las respuestas de error que terminan con exit(): corre la petición en otro proceso PHP y
     * devuelve el estado HTTP (informado por una función de cierre, que corre aunque haya exit) y la salida.
     *
     * @param array<string, string> $server
     *
     * @return array{status: int, body: string}
     */
    private function dispatchIsolated(string $method, string $path, string $routes, array $server = []): array
    {
        $script = tempnam(sys_get_temp_dir(), 'router') . '.php';
        file_put_contents($script, '<?php
            namespace versaWYS\\kernel\\Tests;
            use versaWYS\\kernel\\Request;
            use versaWYS\\kernel\\Router;
            require '
        . var_export(dirname(__DIR__) . '/vendor/autoload.php', true)
        . ';
            require '
        . var_export(__FILE__, true)
        . ';
            $GLOBALS["config"] = '
        . var_export([
            'trusted_proxies' => [],
            'rate_limiting' => ['enabled' => false],
            'admin' => [],
            'build' => ['debug' => false],
        ], true)
        . ';
            $_SERVER = '
        . var_export([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'REMOTE_ADDR' => '127.0.0.1',
            ...$server,
        ], true)
        . ';
            $GLOBALS["request"] = new Request();
            '
        . $routes
        . '
            register_shutdown_function(static function (): void { fwrite(STDERR, "STATUS:" . http_response_code()); });
            (new Router())->resolve();
        ');

        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $body = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        unlink($script);

        preg_match('/STATUS:(\d+)/', $errors, $m);

        return ['status' => (int) ($m[1] ?? 0), 'body' => $body];
    }

    /**
     * Registra una ruta con un prefijo único (las tablas de rutas son estáticas) y devuelve su patrón.
     *
     * @param array<int, array{0: class-string, 1: string}> $middlewares
     */
    private function route(string $method, string $suffix, array $callback, array $middlewares = []): string
    {
        $path = '/kernel-test-' . bin2hex(random_bytes(4)) . rtrim($suffix, '/');
        Router::$method($path, $callback, $middlewares);

        return $path;
    }

    /**
     * @param array<string, string> $server
     */
    private function dispatch(string $method, string $path, array $server = []): string
    {
        global $request;

        http_response_code(200);
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1', ...$server];
        $request = new Request();

        ob_start();

        try {
            (new Router())->resolve();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}

final class RouterTestController
{
    public function show(): string
    {
        return 'ok';
    }

    /** @return array<string, mixed> */
    public function unauthorized(): array
    {
        return ['success' => 0, 'message' => 'No autenticado.', 'code' => 401];
    }

    /** @return array<string, mixed> */
    public function pdoCode(): array
    {
        return ['success' => 0, 'message' => 'Error', 'code' => 23000];
    }

    public function echo(string $id): string
    {
        return 'id=' . $id;
    }

    public function explode(): never
    {
        throw new \RuntimeException(
            'PDO engine execution error: SQL failed (get): SELECT password FROM versa_users en /var/www/app/models/Users.php:40',
        );
    }
}

final class RouterTestMiddleware
{
    /** @return array<string, mixed> */
    public function deny(): array
    {
        return ['success' => 0, 'message' => 'Sin permiso', 'code' => 403];
    }
}
