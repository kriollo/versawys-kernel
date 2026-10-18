<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\helpers\Functions;
use versaWYS\kernel\Request;

/** Accesos tipados a la entrada: el cliente puede mandar cualquier tipo por JSON (type juggling). */
final class TypedInputTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        Request::setInput(null);
    }

    public function testStringConvierteEscalaresYRechazaArreglos(): void
    {
        $r = $this->request([
            'texto' => 'hola',
            'numero' => 7,
            'si' => true,
            'lista' => ['a'],
            'objeto' => ['a' => 1],
            'nulo' => null,
        ]);

        self::assertSame('hola', $r->string('texto'));
        self::assertSame('7', $r->string('numero'));
        self::assertSame('1', $r->string('si'));
        self::assertSame('', $r->string('lista'), 'nunca "Array"');
        self::assertSame('x', $r->string('objeto', 'x'));
        self::assertSame('', $r->string('nulo'));
        self::assertSame('defecto', $r->string('no-viene', 'defecto'));
    }

    public function testIntegerSoloAceptaEnterosEscritosComoTales(): void
    {
        $r = $this->request([
            'a' => 42,
            'b' => '42',
            'c' => '-3',
            'd' => '1e3',
            'e' => '0x1A',
            'f' => '1.5',
            'g' => ' 1',
            'h' => true,
            'i' => ['1'],
            'j' => 1.9,
        ]);

        self::assertSame(42, $r->integer('a'));
        self::assertSame(42, $r->integer('b'));
        self::assertSame(-3, $r->integer('c'));
        foreach (['d', 'e', 'f', 'g', 'h', 'i', 'j'] as $campo) {
            self::assertSame(0, $r->integer($campo), "{$campo} no es un entero");
        }

        self::assertSame(15, $r->integer('no-viene', 15));
    }

    public function testFunctionsTextEsLaMismaReglaParaUnArregloYaLeido(): void
    {
        self::assertSame('5', Functions::text(5));
        self::assertSame('', Functions::text(['x']));
        self::assertSame('n/a', Functions::text(null, 'n/a'));
    }

    private function request(array $json): Request
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        Request::setInput((string) json_encode($json));

        return new Request();
    }
}
