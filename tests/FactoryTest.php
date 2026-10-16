<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use versaWYS\kernel\Testing\Factory;

final class FactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::reset();
    }

    public function testLosValoresDadosReemplazanLosDeLaDefinicionYElNumeroNoSeRepite(): void
    {
        Factory::define('ordenes', static fn(int $n): array => ['nombre' => "Orden {$n}", 'id_empresa' => 1]);

        $a = Factory::make('ordenes');
        $b = Factory::make('ordenes', ['id_empresa' => 7]);

        self::assertSame(1, $a['id_empresa']);
        self::assertSame(7, $b['id_empresa']);
        self::assertNotSame($a['nombre'], $b['nombre']);
    }

    public function testSinDefinicionDiceComoCrearla(): void
    {
        $this->expectExceptionMessage('factory:make');
        Factory::make('no_existe');
    }

    public function testNombresDeTablaOColumnaRarosNoLleganAlSql(): void
    {
        Factory::define('ordenes', static fn(int $n): array => ['nombre' => 'x']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no válido');
        Factory::create('ordenes', ['nombre"); DROP TABLE x; --' => 'y']);
    }
}
