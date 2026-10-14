<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\helpers\Functions;

final class ValidationTest extends TestCase
{
    public function testLengthsCountCharactersNotBytes(): void
    {
        self::assertSame([], Functions::validateParams(['nombre' => 'José'], ['nombre' => 'required|max:4']));
        self::assertArrayHasKey('nombre', Functions::validateParams(['nombre' => 'Joséé'], ['nombre' => 'max:4']));
    }

    public function testNullableFieldsAreValidatedOnlyWhenPresent(): void
    {
        $rules = ['telefono' => 'nullable|numeric', 'nota' => 'nullable|max:3'];

        self::assertSame([], Functions::validateParams([], $rules));
        self::assertSame([], Functions::validateParams(['telefono' => ''], $rules));
        self::assertArrayHasKey('telefono', Functions::validateParams(['telefono' => 'abc'], $rules));
        self::assertArrayHasKey('nota', Functions::validateParams(['nota' => 'larga'], $rules));
    }

    public function testInAcceptsOnlyTheListedValues(): void
    {
        $rules = ['estado' => 'required|in:activo,inactivo'];

        self::assertSame([], Functions::validateParams(['estado' => 'activo'], $rules));
        self::assertSame(
            ['estado' => 'El campo estado debe ser uno de: activo, inactivo'],
            Functions::validateParams(['estado' => 'borrado'], $rules),
        );
        self::assertArrayHasKey('estado', Functions::validateParams(['estado' => ['activo']], $rules));
    }

    public function testBooleanAcceptsJsonAndFormValues(): void
    {
        foreach ([true, false, 1, 0, '1', '0', 'true', 'false', 'on', 'off'] as $value) {
            self::assertSame(
                [],
                Functions::validateParams(['ok' => $value], ['ok' => 'boolean']),
                var_export($value, true),
            );
        }
        self::assertArrayHasKey('ok', Functions::validateParams(['ok' => 'quizás'], ['ok' => 'boolean']));
    }

    public function testZeroAndFalseAreValuesForRequired(): void
    {
        $rules = ['cantidad' => 'required', 'estado' => 'required', 'activo' => 'required'];

        self::assertSame([], Functions::validateParams(['cantidad' => 0, 'estado' => '0', 'activo' => false], $rules));
        self::assertSame(
            [
                'cantidad' => 'El campo cantidad es requerido',
                'estado' => 'El campo estado es requerido',
                'activo' => 'El campo activo es requerido',
            ],
            Functions::validateParams(['cantidad' => '', 'estado' => '   ', 'activo' => []], $rules),
        );
    }

    public function testTheValuesAreNeverRewritten(): void
    {
        $params = ['nombre' => "O'Brien <b>"];
        Functions::validateParams($params, ['nombre' => 'required|string']);

        self::assertSame("O'Brien <b>", $params['nombre']);
    }
}
