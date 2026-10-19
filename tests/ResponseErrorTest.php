<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use versaWYS\kernel\Output;
use versaWYS\kernel\RequestHalted;
use versaWYS\kernel\Response;

/** Las páginas de error y las respuestas especiales de Response, tal como llegan al navegador. */
final class ResponseErrorTest extends TestCase
{
    private mixed $originalConfig;

    private mixed $originalTwig;

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        global $config, $twig;

        $this->originalConfig = $config ?? null;
        $this->originalTwig = $twig ?? null;
        $this->server = $_SERVER;
        $config = ['build' => ['debug' => false], 'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/']];
        $twig = null;
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/x'];
        Response::resetRequestState();
        error_clear_last();
    }

    protected function tearDown(): void
    {
        global $config, $twig;

        $config = $this->originalConfig;
        $twig = $this->originalTwig;
        $_SERVER = $this->server;
        Response::resetRequestState();
    }

    public function testSinTwigMuestraUnaPaginaSimpleConElMensajeEscapado(): void
    {
        [$status, , $body] = self::capture(static fn(): bool => Response::jsonError([
            'message' => '<script>alert(1)</script>',
        ], 500));

        self::assertSame(500, $status);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
    }

    public function testConTwigUsaLaPlantillaDeError(): void
    {
        self::twig(['versaWYS/debugError.twig' => 'ERROR: {{ data.message }}']);

        [$status, , $body] = self::capture(static fn(): bool => Response::jsonError([
            'message' => 'No encontrado',
        ], 404));

        self::assertSame(404, $status);
        self::assertSame('ERROR: No encontrado', $body);
    }

    public function testSiLaPlantillaDeErrorFallaCaeEnLaSiguienteHastaUnaPaginaSimple(): void
    {
        // debugError usa una variable que no existe en modo estricto; simpleError existe
        self::twig([
            'versaWYS/debugError.twig' => '{% include "no-existe.twig" %}',
            'versaWYS/simpleError.twig' => 'SIMPLE: {{ data.message }}',
        ]);
        [, , $body] = self::capture(static fn(): bool => Response::jsonError(['message' => 'Falló'], 500));
        self::assertSame('SIMPLE: Falló', $body);

        // Sin ninguna plantilla que sirva: la página simple, sin Twig
        Response::resetRequestState();
        self::twig(['versaWYS/debugError.twig' => '{% include "no-existe.twig" %}']);
        [$status, , $body] = self::capture(static fn(): bool => Response::jsonError([
            'message' => 'Falló otra vez',
        ], 500));
        self::assertSame(500, $status);
        self::assertStringContainsString('Falló otra vez', $body);
    }

    public function testUnBucleDeErroresTerminaEnLaPaginaSimple(): void
    {
        self::twig(['versaWYS/debugError.twig' => 'PLANTILLA']);

        foreach ([1, 2] as $_) {
            [, , $body] = self::capture(static fn(): bool => Response::jsonError(['message' => 'x'], 500));
            self::assertSame('PLANTILLA', $body);
        }

        // El tercero de la misma petición ya no intenta Twig: así un error en la plantilla no se repite
        [, , $body] = self::capture(static fn(): bool => Response::jsonError(['message' => 'tercero'], 500));
        self::assertStringContainsString('tercero', $body);
        self::assertNotSame('PLANTILLA', $body);
    }

    public function testEnUnaNavegacionSpaDevuelveHtmlParaElPanelSinDetallesEnProduccion(): void
    {
        $_SERVER['HTTP_X_SPA'] = '1';

        [$status, $headers, $body] = self::capture(static fn(): bool => Response::jsonError([
            'message' => 'SQLSTATE[42P01] relación no existe',
            'file' => '/var/www/app/models/Users.php',
        ], 500));
        $json = json_decode($body, true);

        self::assertSame(500, $status);
        self::assertSame(['application/json'], $headers['content-type'] ?? null);
        self::assertSame('html', $json['type']);
        self::assertStringContainsString('Error interno', $json['content']);
        self::assertStringNotContainsString('SQLSTATE', $json['content']);
        self::assertStringNotContainsString('/var/www', $json['content']);
    }

    public function testEnUnaNavegacionSpaEnDebugMuestraElDetalleEscapado(): void
    {
        global $config;
        $config['build']['debug'] = true;
        $_SERVER['HTTP_X_SPA'] = '1';

        [, , $body] = self::capture(static fn(): bool => Response::jsonError([
            'message' => 'Falló <b>esto</b>',
            'type' => 'RuntimeException',
            'file' => '/app/x.php',
            'line' => 12,
        ], 500));
        $content = json_decode($body, true)['content'];

        self::assertStringContainsString('Falló &lt;b&gt;esto&lt;/b&gt;', $content);
        self::assertStringContainsString('/app/x.php', $content);
        self::assertStringContainsString('12', $content);
    }

    public function testHeadNoLlevaCuerpo(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';

        [$status, , $body] = self::capture(static fn(): bool => Response::jsonError(['message' => 'x'], 503));
        self::assertSame(503, $status);
        self::assertSame('', $body);

        [, , $body] = self::capture(static fn(): bool|string => Response::json(['success' => 1]));
        self::assertSame('', $body);
    }

    public function testTextoPlano(): void
    {
        [$status, $headers, $body] = self::capture(static fn(): bool|string => Response::textPlain('pong', 202));

        self::assertSame(202, $status);
        self::assertSame(['text/plain'], $headers['content-type'] ?? null);
        self::assertSame('pong', $body);
    }

    public function testUnAvisoPrevioEnProduccionSeRegistraYLaRespuestaSigue(): void
    {
        // Suprimido a propósito: así PHP lo deja en error_get_last(), que es lo que revisa Response
        // @mago-expect lint:no-error-control-operator
        @trigger_error('aviso de prueba', E_USER_WARNING);

        [$status, , $body] = self::capture(static fn(): bool|string => Response::json(['success' => 1, 'dato' => 7]));

        self::assertSame(200, $status);
        self::assertSame(['success' => 1, 'dato' => 7], json_decode($body, true));
    }

    public function testUnAvisoPrevioEnDebugSeMuestraComoError(): void
    {
        global $config;
        $config['build']['debug'] = true;
        // Suprimido a propósito: así PHP lo deja en error_get_last(), que es lo que revisa Response
        // @mago-expect lint:no-error-control-operator
        @trigger_error('aviso de prueba', E_USER_WARNING);

        [$status, , $body] = self::capture(static fn(): bool|string => Response::json(['success' => 1]));

        self::assertSame(500, $status);
        self::assertStringContainsString('aviso de prueba', $body);
    }

    public function testRedireccionNormalYEnNavegacionSpa(): void
    {
        [$status, $headers] = self::capture(static fn() => Response::redirect('/admin/login'));
        self::assertSame(302, $status);
        self::assertSame(['/admin/login'], $headers['location'] ?? null);

        $_SERVER['HTTP_X_SPA'] = '1';
        [, $headers, $body] = self::capture(static fn() => Response::redirect('/admin/login'));
        self::assertArrayNotHasKey('location', $headers, 'el SPA navega solo: un 302 le cambiaría el protocolo');
        self::assertSame(['type' => 'redirect', 'url' => '/admin/login'], json_decode($body, true));
    }

    /**
     * Ejecuta la respuesta como en una petición real y devuelve [estado, cabeceras, cuerpo].
     *
     * @return array{0: int, 1: array<string, list<string>>, 2: string}
     */
    private static function capture(callable $respond): array
    {
        Output::capture();
        ob_start();

        try {
            $respond();
        } catch (RequestHalted) { // @mago-expect lint:no-empty-catch-clause
            // La respuesta terminó la petición: en producción, exit()
        }

        $body = (string) ob_get_clean();
        $out = Output::release();

        return [$out['status'], $out['headers'], $body];
    }

    /** Un Twig con estas plantillas de error, en memoria */
    private static function twig(array $templates): void
    {
        global $twig;

        $twig = new class(new Environment(new ArrayLoader($templates))) {
            public function __construct(
                private readonly Environment $env,
            ) {}

            public function renderDirect(string $name, array $context): string
            {
                return $this->env->render($name, $context);
            }
        };
    }
}
