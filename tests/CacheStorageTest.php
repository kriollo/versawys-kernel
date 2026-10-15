<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Cache;
use versaWYS\kernel\Storage;

final class CacheStorageTest extends TestCase
{
    private string $dir;

    private mixed $originalConfig = null;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $this->dir = sys_get_temp_dir() . '/versawys_cs_' . bin2hex(random_bytes(6));
        $config = ['cache' => ['dir' => $this->dir . '/cache'], 'storage' => ['root' => $this->dir . '/storage']];
    }

    protected function tearDown(): void
    {
        global $config;

        $config = $this->originalConfig;
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testCacheGuardaVenceYOlvida(): void
    {
        self::assertSame('nada', Cache::get('k', 'nada'));
        Cache::set('k', ['a' => 1, 'b' => null]);
        self::assertSame(['a' => 1, 'b' => null], Cache::get('k'));

        Cache::set('nulo', null);
        self::assertTrue(Cache::has('nulo'), 'null guardado no es lo mismo que ausente');

        Cache::set('vencido', 1, ttl: -1);
        self::assertFalse(Cache::has('vencido'));

        Cache::forget('k');
        self::assertNull(Cache::get('k'));

        Cache::set('x', 1);
        Cache::flush();
        self::assertFalse(Cache::has('x'));
    }

    public function testRememberCalculaSoloLaPrimeraVez(): void
    {
        $llamadas = 0;
        $calcular = static function () use (&$llamadas): int {
            $llamadas++;

            return 42;
        };

        self::assertSame(42, Cache::remember('r', 60, $calcular));
        self::assertSame(42, Cache::remember('r', 60, $calcular));
        self::assertSame(1, $llamadas);
    }

    public function testStorageGuardaLeeListaYBorra(): void
    {
        Storage::put('facturas/1/a.pdf', '%PDF');

        self::assertTrue(Storage::exists('facturas/1/a.pdf'));
        self::assertSame('%PDF', Storage::get('facturas/1/a.pdf'));
        self::assertSame(['facturas/1/a.pdf'], Storage::files('facturas/1'));
        self::assertTrue(Storage::delete('facturas/1/a.pdf'));
        self::assertNull(Storage::get('facturas/1/a.pdf'));
    }

    /** @return iterable<array{string}> */
    public static function rutasPeligrosas(): iterable
    {
        yield ['../config.json'];
        yield ['a/../../config.json'];
        yield ['/etc/passwd'];
        yield ['..\\config.json'];
        yield ["a\0.pdf"];
        yield ['C:/windows'];
    }

    #[DataProvider('rutasPeligrosas')]
    public function testStorageNoSaleDeSuCarpeta(string $ruta): void
    {
        $this->expectException(InvalidArgumentException::class);
        Storage::put($ruta, 'x');
    }
}
