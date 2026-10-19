<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use versaWYS\kernel\ErrorHandler;
use versaWYS\kernel\Output;
use versaWYS\kernel\Request;
use versaWYS\kernel\RequestHalted;
use versaWYS\kernel\Response;

/** Una excepción sin atrapar: lo que ve el cliente en producción y en debug. */
final class ErrorHandlerTest extends TestCase
{
    private mixed $config;

    private mixed $debug;

    private mixed $request;

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->config = $GLOBALS['config'] ?? null;
        $this->debug = $GLOBALS['debug'] ?? null;
        $this->request = $GLOBALS['request'] ?? null;
        $this->server = $_SERVER;
        $GLOBALS['config'] = [
            'build' => ['debug' => false],
            'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/'],
        ];
        $GLOBALS['twig'] = null;
        ErrorHandler::resetRequestState();
        Response::resetRequestState();
    }

    protected function tearDown(): void
    {
        $GLOBALS['config'] = $this->config;
        $GLOBALS['debug'] = $this->debug;
        $GLOBALS['request'] = $this->request;
        $_SERVER = $this->server;
        ErrorHandler::resetRequestState();
    }

    public function testEnProduccionNadieVeElDetalleNiHayManejadoresInline(): void
    {
        foreach ([
            'página' => [],
            'API' => ['HTTP_ACCEPT' => 'application/json'],
            'navegación SPA' => ['HTTP_X_SPA' => '1'],
        ] as $caso => $server) {
            ErrorHandler::resetRequestState();
            [$status, $body] = $this->explota(false, $server);

            self::assertSame(500, $status, $caso);
            self::assertStringNotContainsString('SQLSTATE', $body, $caso);
            self::assertStringNotContainsString('password', $body, $caso);
            // La CSP con nonce bloquea onclick=: un botón así no haría nada
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $body, $caso);
        }
    }

    public function testEnDebugUnaPaginaMuestraElDetalleEscapadoYLaApiLoRecibeEnJson(): void
    {
        [$status, $body] = $this->explota(true);
        self::assertSame(500, $status);
        self::assertStringContainsString('SQLSTATE: SELECT password', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringNotContainsString('<script>x</script>', $body);

        ErrorHandler::resetRequestState();
        [$status, $body] = $this->explota(true, ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(500, $status);
        self::assertStringContainsString('SQLSTATE', $body);
    }

    public function testSeManejaUnaSolaVezPorPeticion(): void
    {
        $this->explota(false);

        [$status, $body] = $this->explota(false);

        self::assertSame(200, $status, 'el segundo error de la misma petición no responde otra vez');
        self::assertSame('', $body);
    }

    public function testUnAvisoSuprimidoNoSeProcesaYUnoVisibleSi(): void
    {
        $previo = error_reporting(0); // lo que hace @ mientras dura la expresión

        try {
            self::assertFalse(ErrorHandler::handleError(E_WARNING, 'suprimido', __FILE__, __LINE__));
            error_reporting(E_ALL);
            self::assertTrue(ErrorHandler::handleError(E_USER_NOTICE, 'visible', __FILE__, __LINE__));
        } finally {
            error_reporting($previo);
        }
    }

    /**
     * @param array<string, string> $server
     *
     * @return array{0: int, 1: string}
     */
    private function explota(bool $debug, array $server = []): array
    {
        $GLOBALS['debug'] = $debug;
        $GLOBALS['config']['build']['debug'] = $debug;
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/x', 'REMOTE_ADDR' => '127.0.0.1'] + $server;
        $GLOBALS['request'] = new Request();

        Output::capture();
        ob_start();

        try {
            ErrorHandler::handleException(
                new RuntimeException('SQLSTATE: SELECT password FROM versa_users <script>x</script>'),
            );
        } catch (RequestHalted) { // @mago-expect lint:no-empty-catch-clause
            // Terminó la petición, como el exit() de producción
        }

        $body = (string) ob_get_clean();

        return [Output::release()['status'], $body];
    }
}
