<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Closure;
use versaWYS\kernel\model\RedisModel;

/**
 * Caché de la aplicación. Por defecto en archivos (`cache.dir`); con `cache.driver: "redis"` usa RedisModel.
 *
 *     $resumen = Cache::remember("resumen_{$idEmpresa}", 300, fn() => $modelo->resumen($idEmpresa));
 *     Cache::forget("resumen_{$idEmpresa}");   // al cambiar los datos
 *
 * Guarda valores que JSON puede representar (escalares y arreglos), igual en los dos almacenes.
 */
final class Cache
{
    private const string PREFIX = 'cache:';

    public static function get(string $key, mixed $default = null): mixed
    {
        $entry = self::read($key);

        return $entry === null ? $default : $entry['v'];
    }

    public static function has(string $key): bool
    {
        return self::read($key) !== null;
    }

    /** @param int $ttl segundos; 0 = no vence (negativo: ya vencido) */
    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        $raw = json_encode(['e' => $ttl !== 0 ? time() + $ttl : 0, 'v' => $value], JSON_THROW_ON_ERROR);
        if (self::redis()) {
            RedisModel::set(self::PREFIX . $key, $raw, $ttl > 0 ? $ttl : null);

            return;
        }

        // Escribir aparte y renombrar: quien lee al mismo tiempo nunca ve un archivo a medias
        $file = self::file($key);
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $raw);
        rename($tmp, $file);
    }

    /**
     * El valor guardado, o el que devuelve `$callback` (que queda guardado `$ttl` segundos).
     *
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    public static function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $entry = self::read($key);
        if ($entry !== null) {
            return $entry['v'];
        }

        $value = $callback();
        self::set($key, $value, $ttl);

        return $value;
    }

    public static function forget(string $key): void
    {
        if (self::redis()) {
            RedisModel::delete(self::PREFIX . $key);

            return;
        }

        $file = self::file($key);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /** Borra toda la caché de la aplicación (no la de Twig ni las sesiones). */
    public static function flush(): void
    {
        if (self::redis()) {
            $keys = RedisModel::scan(self::PREFIX . '*');
            if ($keys !== []) {
                RedisModel::delete($keys);
            }

            return;
        }

        $files = glob(self::dir() . '*.json');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
    }

    /** @return array{e: int, v: mixed}|null */
    private static function read(string $key): ?array
    {
        if (self::redis()) {
            $raw = RedisModel::get(self::PREFIX . $key);
        } else {
            $file = self::file($key);
            $raw = is_file($file) ? file_get_contents($file) : null;
        }

        $entry = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($entry) || !array_key_exists('v', $entry) || !is_int($entry['e'] ?? null)) {
            return null;
        }

        if ($entry['e'] !== 0 && $entry['e'] <= time()) {
            self::forget($key);

            return null;
        }

        return $entry;
    }

    private static function redis(): bool
    {
        global $config;

        return ($config['cache']['driver'] ?? 'file') === 'redis';
    }

    private static function dir(): string
    {
        global $config;

        $dir = rtrim((string) ($config['cache']['dir'] ?? 'versaWYS/cache/data'), '/') . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        return $dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . sha1($key) . '.json';
    }
}
