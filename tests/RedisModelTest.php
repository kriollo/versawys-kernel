<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Loggin;
use versaWYS\kernel\model\RedisModel;

final class RedisModelTest extends TestCase
{
    public function testSinRedisLasOperacionesNoLanzanYElErrorQuedaEnElLog(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Sin la extensión redis');
        }

        $dir = sys_get_temp_dir() . '/versawys_redis_' . bin2hex(random_bytes(4)) . '/';
        Loggin::initialize(['logging' => ['dir' => $dir]]);
        $errors = [];
        Loggin::setCustomHandler(static function (string $level, string $message, string $module) use (&$errors): void {
            $errors[] = [$level, $module];
        });

        // Un puerto donde no escucha nadie
        RedisModel::initialize(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2, 'persistent' => false]);
        RedisModel::close(); // otra prueba pudo dejar una conexión abierta
        $saved = RedisModel::set('clave', 'valor');
        $read = RedisModel::get('clave', 'por defecto');

        Loggin::setCustomHandler(static function (): void {});
        // La carpeta temporal se borra: las pruebas siguientes vuelven a la compartida
        Loggin::initialize(['logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/']]);
        exec('rm -rf ' . escapeshellarg($dir));

        self::assertFalse($saved);
        self::assertSame('por defecto', $read);
        self::assertContains(['ERROR', 'RedisModel'], $errors);
    }
}
