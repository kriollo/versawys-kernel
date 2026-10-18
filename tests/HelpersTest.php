<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use versaWYS\kernel\helpers\Functions;

final class HelpersTest extends TestCase
{
    private mixed $config;

    protected function setUp(): void
    {
        $this->config = $GLOBALS['config'] ?? null;
        $GLOBALS['config'] = [
            'admin' => ['custom_url' => 'gestion'],
            'assets' => ['dashboard' => ['img' => ['dist' => 'public/img']]],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['config'] = $this->config;
    }

    public function testFormatRut(): void
    {
        self::assertSame('12.345.678-5', Functions::formatRut('123456785'));
        self::assertSame('12.345.678-5', Functions::formatRut('12.345.678-5'));
        self::assertSame('7.654.321-K', Functions::formatRut('7654321-k'));
        self::assertSame('', Functions::formatRut(''));
        self::assertSame('5', Functions::formatRut('5'));
    }

    public function testMethodFieldSoloAdmiteLetras(): void
    {
        self::assertSame('<input type="hidden" name="_method" value="PUT">', Functions::method_field(' put '));
        self::assertSame(
            '<input type="hidden" name="_method" value="PUTSCRIPTALERTSCRIPT">',
            Functions::method_field('PUT"><script>alert(1)</script>'),
        );
    }

    public function testRemoveScapeDecodificaVariosNivelesSinBucleInfinito(): void
    {
        self::assertSame('<svg>', Functions::removeScape('&amp;lt;svg&amp;gt;'));
        self::assertSame("O'Higgins", Functions::removeScape("O\\'Higgins"));
        self::assertSame('texto', Functions::removeScape('texto'));
    }

    public function testUrlsDelPanelYAssets(): void
    {
        self::assertSame('/gestion/', Functions::getPanelUrl());
        self::assertSame('/gestion/usuarios', Functions::getPanelUrl('/usuarios'));
        self::assertSame('/gestion/usuarios', Functions::getPanelUrl('usuarios'));
        self::assertSame('public/img/logo.png', Functions::getAssets('dashboard', 'img', '/logo.png', false));
        self::assertSame('mi_nombre_largo', Functions::replace_spaces_with_underscore('mi nombre largo'));

        $this->expectException(Exception::class);
        Functions::getAssets('dashboard', 'no-existe', 'x');
    }
}
