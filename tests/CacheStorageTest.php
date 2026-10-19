<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Cache;
use versaWYS\kernel\model\RedisModel;
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
        RedisModel::close();
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

    public function testConRedisGuardaVenceYOlvidaIgualQueEnArchivos(): void
    {
        $this->usarRedis('proyecto-a');

        Cache::set('resumen', ['total' => 3], 60);
        self::assertSame(['total' => 3], Cache::get('resumen'));
        self::assertGreaterThan(0, RedisModel::ttl($this->redisKeys()[0] ?? ''), 'Redis vence la clave solo');

        self::assertSame(5, Cache::remember('contador', 60, static fn(): int => 5));
        self::assertSame(5, Cache::remember('contador', 60, static fn(): int => 9));

        Cache::set('vencida', 'x', -1);
        self::assertNull(Cache::get('vencida'));

        Cache::forget('resumen');
        self::assertFalse(Cache::has('resumen'));
        Cache::flush();
        self::assertSame([], $this->redisKeys());
    }

    public function testDosProyectosEnElMismoRedisNoSeMezclan(): void
    {
        $this->usarRedis('proyecto-a');
        Cache::set('resumen', 'de A');

        $this->usarRedis('proyecto-b');
        self::assertNull(Cache::get('resumen'), 'B no lee la caché de A');
        Cache::set('resumen', 'de B');
        Cache::flush();

        $this->usarRedis('proyecto-a');
        self::assertSame('de A', Cache::get('resumen'), 'el flush de B no borra la de A');
        Cache::flush();
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

    /** La base 15 de Redis local, para no tocar datos de desarrollo; sin Redis, la prueba se omite */
    private function usarRedis(string $sessionKey): void
    {
        global $config;

        if (!extension_loaded('redis')) {
            self::markTestSkipped('Sin la extensión redis');
        }

        RedisModel::initialize([
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 15,
            'timeout' => 0.5,
            'persistent' => false,
        ]);
        RedisModel::close();
        if (!RedisModel::ping()) {
            self::markTestSkipped('Redis no responde en 127.0.0.1:6379');
        }

        $config['cache']['driver'] = 'redis';
        $config['session']['key'] = str_repeat($sessionKey, 8);
    }

    /** @return list<string> */
    private function redisKeys(): array
    {
        return RedisModel::scan('cache:*');
    }
}
