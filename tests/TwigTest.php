<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\versaTwig;

final class TwigTest extends TestCase
{
    private string $dir;

    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $this->dir = sys_get_temp_dir() . '/versawys-twig-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/tpl', 0o777, true);
        file_put_contents(
            $this->dir . '/tpl/ficha.twig',
            '<p>{{ nombre }}</p><input value="{{ nombre }}"><div>{{ html|raw }}</div><script nonce="{{ csp_nonce() }}"></script>',
        );
    }

    protected function tearDown(): void
    {
        global $config;

        exec('rm -rf ' . escapeshellarg($this->dir));
        $config = $this->originalConfig;
    }

    public function testWhatAUserWroteIsEscapedEvenIfConfigAsksOtherwise(): void
    {
        // twig.auto_escape en false no tiene efecto: el escape no se apaga por configuración
        $html = $this->twig(false)->renderDirect('ficha', [
            'nombre' => '"><script>alert(1)</script>',
            'html' => '<b>propio</b>',
        ]);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('value="&quot;&gt;', $html);
        // |raw es la única salida sin escape, y queda a cargo de quien la usa
        self::assertStringContainsString('<div><b>propio</b></div>', $html);
    }

    public function testCspNonceIsEmptyUnlessEnabled(): void
    {
        self::assertStringContainsString('<script nonce="">', $this->twig(false)->renderDirect('ficha', [
            'nombre' => '',
            'html' => '',
        ]));
        self::assertMatchesRegularExpression('/<script nonce="[A-Za-z0-9+\/=]{24}">/', $this->twig(
            true,
        )->renderDirect('ficha', ['nombre' => '', 'html' => '']));
    }

    private function twig(bool $nonce): versaTwig
    {
        global $config;
        $config = [
            'twig' => [
                'cache' => false,
                'compiled_dir' => $this->dir . '/cache',
                'templates_dir' => $this->dir . '/tpl',
                'strict_variables' => false,
                'auto_escape' => false,
            ],
            'build' => ['debug' => false, 'charset' => 'utf-8'],
            'csp' => ['nonce' => $nonce],
        ];

        return new versaTwig($config);
    }
}
