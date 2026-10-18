<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Validator;

final class ValidatorTest extends TestCase
{
    public function testReglasComoTextoOArregloDanLosMismosMensajesQueAntes(): void
    {
        $datos = ['email' => 'x', 'nombre' => ''];

        $texto = Validator::make($datos, [
            'email' => 'required|email',
            'nombre' => 'required',
            'falta' => 'required',
        ])->errors();
        $arreglo = Validator::make($datos, [
            'email' => ['required', 'email'],
            'nombre' => ['required'],
            'falta' => ['required'],
        ])->errors();

        self::assertSame($texto, $arreglo);
        self::assertSame(
            [
                'email' => 'El campo email no es un email valido',
                'nombre' => 'El campo nombre es obligatorio',
                'falta' => 'El campo falta es obligatorio',
            ],
            $texto,
        );
    }

    public function testDatosAnidadosYCadaElementoDeUnArreglo(): void
    {
        $datos = ['cliente' => ['email' => 'no-es'], 'items' => [['cantidad' => 2], ['cantidad' => 0], []]];

        $errores = Validator::make($datos, [
            'cliente.email' => 'required|email',
            'items' => 'required|array',
            'items.*.cantidad' => 'required|int:positive',
        ])->errors();

        self::assertSame(
            [
                'cliente.email' => 'El campo cliente.email no es un email valido',
                'items.1.cantidad' => 'El campo items.1.cantidad debe ser un numero positivo',
                'items.2.cantidad' => 'El campo items.2.cantidad es obligatorio',
            ],
            $errores,
        );
    }

    public function testReglaPropiaYMensajesPorCampoYPorRegla(): void
    {
        $par = static fn(string $campo, mixed $valor): ?string => ((int) $valor % 2) === 0
            ? null
            : "{$campo} debe ser par";

        $v = Validator::make(
            ['n' => 3, 'titulo' => '', 'items' => [['x' => '']]],
            [
                'n' => ['required', 'int', $par],
                'titulo' => 'required',
                'items.*.x' => 'required',
            ],
            ['titulo.required' => 'Ponle un título', 'items.*.x' => 'Falta x'],
        );

        self::assertSame(
            ['n' => 'n debe ser par', 'titulo' => 'Ponle un título', 'items.0.x' => 'Falta x'],
            $v->errors(),
        );
    }

    public function testValidatedDevuelveSoloLoQueTieneReglasSinModificarlo(): void
    {
        $v = Validator::make([
            'nombre' => '  Ana  ',
            'rol' => 'admin',
            'cliente' => ['email' => 'a@b.cl', 'extra' => 1],
            'opcional' => '',
        ], ['nombre' => 'required', 'cliente.email' => 'email', 'opcional' => 'nullable|email']);

        self::assertFalse($v->fails());
        self::assertSame(
            ['nombre' => '  Ana  ', 'cliente' => ['email' => 'a@b.cl'], 'opcional' => ''],
            $v->validated(),
        );
    }

    public function testUnArregloNoPasaMinNiMaxComoSiFueraElTextoArray(): void
    {
        // (string) ['x'] es "Array": 5 caracteres que pasarían min:3 o max:10
        $v = Validator::make(['nombre' => ['x'], 'codigo' => ['a' => 'b']], [
            'nombre' => 'min:3',
            'codigo' => 'max:10',
        ]);

        self::assertTrue($v->fails());
        self::assertSame(
            ['nombre' => 'El campo nombre debe ser texto', 'codigo' => 'El campo codigo debe ser texto'],
            $v->errors(),
        );
    }

    public function testUnaReglaMalEscritaNoDejaPasarElDato(): void
    {
        self::assertSame(
            ['x' => 'Regla de validación desconocida para x: requird'],
            Validator::make(['x' => 'algo'], ['x' => 'requird'])->errors(),
        );
    }

    public function testIntConListaDeValoresAceptaLosListados(): void
    {
        // Antes comparaba el número con la lista como texto y nunca coincidía
        self::assertSame([], Validator::make(['estado' => '2'], ['estado' => 'int:1,2,3'])->errors());
        self::assertSame(
            ['estado' => 'El campo estado debe ser numerico y debe ser uno de los siguientes valores 1,2,3'],
            Validator::make(['estado' => '5'], ['estado' => 'int:1,2,3'])->errors(),
        );
    }
}
