<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Container;
use versaWYS\kernel\FormRequest;
use versaWYS\kernel\Gate;
use versaWYS\kernel\Output;
use versaWYS\kernel\Request;
use versaWYS\kernel\RequestHalted;

final class GuardarCosa extends FormRequest
{
    public static bool $permitir = true;

    #[Override]
    public function rules(): array
    {
        return ['nombre' => 'required|max:10', 'tags.*' => 'string'];
    }

    #[Override]
    public function authorize(): bool
    {
        return self::$permitir;
    }
}

final class ControladorCosas
{
    public static int $llamadas = 0;

    public function guardar(GuardarCosa $datos): array
    {
        self::$llamadas++;

        return $datos->validated();
    }
}

final class PoliticaFacturas
{
    /** @param array<string, mixed> $usuario */
    public function anular(array $usuario, array $factura): bool
    {
        return $factura['autor'] === $usuario['id'];
    }
}

final class AuthorizationTest extends TestCase
{
    private Container $c;

    protected function setUp(): void
    {
        $this->c = new Container();
        ControladorCosas::$llamadas = 0;
        GuardarCosa::$permitir = true;
        Gate::reset();
    }

    protected function tearDown(): void
    {
        Gate::reset();
        Request::setInput(null);
        $_SERVER = [];
    }

    public function testFormRequestValidaAntesDeLlamarAlControlador(): void
    {
        [$estado, $cuerpo] = $this->peticion(['nombre' => 'demasiado largo para diez']);

        self::assertSame(422, $estado);
        self::assertSame(
            'El campo nombre debe tener maximo 10 caracteres',
            json_decode($cuerpo, true)['errors']['nombre'],
        );
        self::assertSame(0, ControladorCosas::$llamadas);
    }

    public function testFormRequestValidaEntregaSoloLoValidado(): void
    {
        [$estado, , $resultado] = $this->peticion(['nombre' => 'ok', 'tags' => ['a'], 'rol' => 'admin']);

        self::assertSame(200, $estado);
        self::assertSame(['nombre' => 'ok', 'tags' => ['a']], $resultado);
    }

    public function testFormRequestSinPermisoResponde403(): void
    {
        GuardarCosa::$permitir = false;
        [$estado] = $this->peticion(['nombre' => 'ok']);

        self::assertSame(403, $estado);
        self::assertSame(0, ControladorCosas::$llamadas);
    }

    public function testGateConCierrePoliticaYBefore(): void
    {
        $ana = ['id' => 1, 'role' => 'user'];
        Gate::define('tableros.ver', static fn(array $u, array $t): bool => $t['id_empresa'] === 7);
        Gate::define('facturas.anular', [PoliticaFacturas::class, 'anular']);

        self::assertTrue(Gate::check($ana, 'tableros.ver', ['id_empresa' => 7]));
        self::assertFalse(Gate::check($ana, 'tableros.ver', ['id_empresa' => 8]));
        self::assertTrue(Gate::check($ana, 'facturas.anular', ['autor' => 1]));
        self::assertFalse(Gate::check($ana, 'facturas.anular', ['autor' => 2]));
        self::assertFalse(Gate::check($ana, 'no.definida'));

        Gate::before(static fn(array $u): ?bool => $u['role'] === 'superadmin' ? true : null);
        self::assertTrue(Gate::check(['id' => 9, 'role' => 'superadmin'], 'facturas.anular', ['autor' => 1]));
        self::assertFalse(Gate::check($ana, 'facturas.anular', ['autor' => 2]));
    }

    /** @return array{0: int, 1: string, 2: mixed} estado, cuerpo y lo que devolvió el controlador */
    private function peticion(array $cuerpo): array
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/x', 'CONTENT_TYPE' => 'application/json'];
        Request::setInput((string) json_encode($cuerpo));
        $this->c->instance(Request::class, new Request());
        Output::capture();
        ob_start();
        $resultado = null;

        try {
            $resultado = $this->c->call(new ControladorCosas(), 'guardar');
        } catch (RequestHalted) { // @mago-expect lint:no-empty-catch-clause
            // La respuesta 422 ya quedó en Output; aquí solo importa que el controlador no se llamó
        }

        $cuerpoRespuesta = (string) ob_get_clean();

        return [Output::release()['status'], $cuerpoRespuesta, $resultado];
    }
}
