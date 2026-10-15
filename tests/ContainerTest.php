<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use versaWYS\kernel\Container;
use versaWYS\kernel\RouteParameterMismatch;

interface Reloj
{
    public function ahora(): string;
}

final class RelojFijo implements Reloj
{
    #[Override]
    public function ahora(): string
    {
        return '2026-10-15';
    }
}

final class Repositorio
{
    public function __construct(
        public readonly Reloj $reloj,
    ) {}
}

final class Servicio
{
    public function __construct(
        public readonly Repositorio $repo,
        public readonly int $limite = 10,
    ) {}
}

final class CicloA
{
    public function __construct(CicloB $b) {}
}

final class CicloB
{
    public function __construct(CicloA $a) {}
}

final class ControladorDePrueba
{
    public function ver(Servicio $servicio, int $id): string
    {
        return $servicio->repo->reloj->ahora() . '#' . $id;
    }

    public function texto(string $slug, ?string $extra = null): string
    {
        return $slug . '|' . ($extra ?? '-');
    }

    public function sinParametros(): int
    {
        return func_num_args();
    }
}

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
