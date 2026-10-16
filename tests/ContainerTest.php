<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use versaWYS\kernel\Container;
use versaWYS\kernel\RouteParameterMismatch;
use versaWYS\kernel\Tests\Fixtures\CicloA;
use versaWYS\kernel\Tests\Fixtures\ControladorDePrueba;
use versaWYS\kernel\Tests\Fixtures\Reloj;
use versaWYS\kernel\Tests\Fixtures\RelojFijo;
use versaWYS\kernel\Tests\Fixtures\Repositorio;
use versaWYS\kernel\Tests\Fixtures\Servicio;

final class ContainerTest extends TestCase
{
    private Container $c;

    protected function setUp(): void
    {
        $this->c = new Container();
        $this->c->bind(Reloj::class, static fn(): Reloj => new RelojFijo());
    }

    public function testResuelveLasDependenciasPorTipoRecursivamente(): void
    {
        $servicio = $this->c->get(Servicio::class);

        self::assertInstanceOf(RelojFijo::class, $servicio->repo->reloj);
        self::assertSame(10, $servicio->limite);
    }

    public function testBindCreaUnoNuevoYSingletonSiempreElMismo(): void
    {
        self::assertNotSame($this->c->get(Reloj::class), $this->c->get(Reloj::class));

        $this->c->singleton(Reloj::class, static fn(): Reloj => new RelojFijo());
        self::assertSame($this->c->get(Reloj::class), $this->c->get(Reloj::class));
    }

    public function testInstanceReemplazaUnServicioPorUnDoble(): void
    {
        $doble = new class() implements Reloj {
            #[Override]
            public function ahora(): string
            {
                return 'doble';
            }
        };
        $this->c->instance(Reloj::class, $doble);

        self::assertSame('doble', $this->c->get(Repositorio::class)->reloj->ahora());
    }

    public function testUnaInterfazSinRegistrarDaUnErrorClaro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('interfaz o clase abstracta');

        (new Container())->get(Repositorio::class);
    }

    public function testDetectaLasDependenciasCirculares(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dependencia circular');

        $this->c->get(CicloA::class);
    }

    public function testCallInyectaServiciosYPasaLosParametrosDeLaRutaComoNumero(): void
    {
        self::assertSame('2026-10-15#42', $this->c->call(new ControladorDePrueba(), 'ver', ['42']));
    }

    public function testUnParametroIntQueNoEsNumeroEsUnaRutaQueNoCalza(): void
    {
        $this->expectException(RouteParameterMismatch::class);

        $this->c->call(new ControladorDePrueba(), 'ver', ['abc']);
    }

    public function testSinTiposSeComportaComoAntes(): void
    {
        $controlador = new ControladorDePrueba();

        self::assertSame('a|b', $this->c->call($controlador, 'texto', ['a', 'b']));
        self::assertSame('a|-', $this->c->call($controlador, 'texto', ['a']));
        // Un método sin parámetros declarados recibe igual los de la ruta (func_get_args)
        self::assertSame(2, $this->c->call($controlador, 'sinParametros', ['x', 'y']));
    }
}
