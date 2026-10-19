<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use versaWYS\kernel\Crypt;
use versaWYS\kernel\SignedUrl;

final class CryptTest extends TestCase
{
    private mixed $originalConfig = null;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $config = ['session' => ['key' => bin2hex(random_bytes(32))]];
    }

    protected function tearDown(): void
    {
        global $config;

        $config = $this->originalConfig;
    }

    public function testCifraYDescifraTextosYArreglos(): void
    {
        foreach (['sk_live_123', ['valor' => 'abc', 'n' => 1], 42] as $value) {
            $encrypted = Crypt::encrypt($value);

            self::assertTrue(Crypt::isEncrypted($encrypted));
            self::assertStringNotContainsString('sk_live', $encrypted);
            self::assertSame($value, Crypt::decrypt($encrypted));
        }

        self::assertNotSame(Crypt::encrypt('x'), Crypt::encrypt('x'), 'cada cifrado usa un nonce nuevo');
    }

    public function testUnValorAlteradoNoSeDescifra(): void
    {
        $encrypted = Crypt::encrypt('secreto');
        $tampered = substr($encrypted, 0, -2) . (str_ends_with($encrypted, 'A') ? 'BB' : 'AA');

        $this->expectException(RuntimeException::class);
        Crypt::decrypt($tampered);
    }

    public function testConOtraClaveNoSeDescifra(): void
    {
        global $config;

        $encrypted = Crypt::encrypt('secreto');
        $config['session']['key'] = bin2hex(random_bytes(32));

        $this->expectExceptionMessage('otra clave');
        Crypt::decrypt($encrypted);
    }

    public function testElEnlaceFirmadoValeHastaQueVenceYNoSePuedeAlterar(): void
    {
        $url = SignedUrl::make('/facturas/15/pdf', 3600, ['formato' => 'a4']);

        self::assertTrue(SignedUrl::isValid($url));
        self::assertFalse(SignedUrl::isValid($url, time() + 3601), 'vencido');
        self::assertFalse(SignedUrl::isValid(str_replace('/15/', '/16/', $url)), 'otra factura');
        self::assertFalse(SignedUrl::isValid(str_replace('formato=a4', 'formato=a3', $url)), 'otro parámetro');
        self::assertFalse(
            SignedUrl::isValid((string) preg_replace('/expires=\d+/', 'expires=9999999999', $url)),
            'vencimiento extendido',
        );
        self::assertFalse(SignedUrl::isValid('/facturas/15/pdf'), 'sin firma');
    }

    public function testElMiddlewareRespondeLaPeticionActual(): void
    {
        $server = $_SERVER;
        $_SERVER['REQUEST_URI'] = SignedUrl::make('/descargas/7', 60);

        try {
            self::assertTrue((new SignedUrl())->check());
            $_SERVER['REQUEST_URI'] = '/descargas/7?expires=1&signature=x';
            self::assertSame(403, (new SignedUrl())->check()['code'] ?? null);
        } finally {
            $_SERVER = $server;
        }
    }
}
