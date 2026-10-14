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
        $path = $this->route('get', '/', [RouterTestController::class, 'show'], [[RouterTestMiddleware::class, 'deny']]);

        $output = $this->dispatch('GET', $path);

        self::assertSame(403, http_response_code());
        self::assertStringNotContainsString('ok', $output);
    }

    public function testRouteParametersReachTheController(): void
    {
        $path = $this->route('get', '/{id}', [RouterTestController::class, 'echo']);

        self::assertSame('id=42', $this->dispatch('GET', str_replace('{id}', '42', $path)));
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

    private function dispatch(string $method, string $path): string
    {
        global $request;

        http_response_code(200);
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1'];
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
}

final class RouterTestMiddleware
{
    /** @return array<string, mixed> */
    public function deny(): array
    {
        return ['success' => 0, 'message' => 'Sin permiso', 'code' => 403];
    }
}
