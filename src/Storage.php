<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use InvalidArgumentException;
use RuntimeException;

/**
 * Archivos de la aplicación bajo una carpeta raíz (`storage.root`, por defecto `versaWYS/storage`, que el
 * servidor web no sirve). Las rutas son relativas a esa raíz y nunca salen de ella.
 *
 *     Storage::put("facturas/{$idEmpresa}/{$folio}.pdf", $pdf);
 *     $pdf = Storage::get("facturas/{$idEmpresa}/{$folio}.pdf");
 *     readfile(Storage::path(...));   // para entregarlo, después de revisar el permiso
 *
 * ponytail: solo disco local; un adaptador S3 se agrega cuando un proyecto lo necesite.
 */
final class Storage
{
    public static function put(string $path, string $contents): void
    {
        $file = self::path($path);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("No se pudo crear la carpeta de {$path}");
        }

        // Escribir aparte y renombrar: nadie lee un archivo a medio escribir
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $file)) {
            throw new RuntimeException("No se pudo guardar {$path}");
        }
    }

    public static function get(string $path): ?string
    {
        $file = self::path($path);
        if (!is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);

        return $contents === false ? null : $contents;
    }

    public static function exists(string $path): bool
    {
        return is_file(self::path($path));
    }

    public static function delete(string $path): bool
    {
        $file = self::path($path);

        return is_file($file) && unlink($file);
    }

    /** @return list<string> los archivos de una carpeta (rutas relativas a la raíz) */
    public static function files(string $dir = ''): array
    {
        $base = self::path($dir);
        $root = self::root();
        $out = [];
        $files = glob(rtrim($base, '/') . '/*');
        foreach ($files === false ? [] : $files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $out[] = substr($file, strlen($root));
        }

        return $out;
    }

    /** La ruta absoluta en disco. Rechaza `..`, rutas absolutas y bytes nulos. */
    public static function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (
            str_contains($path, "\0")
            || str_starts_with($path, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $path) === 1
            || preg_match('/^[a-zA-Z]:/', $path) === 1
        ) {
            throw new InvalidArgumentException("Ruta no permitida en Storage: {$path}");
        }

        return self::root() . ltrim($path, '/');
    }

    private static function root(): string
    {
        global $config;

        $root = (string) ($config['storage']['root'] ?? 'versaWYS/storage');
        // Relativa a la raíz del proyecto (el directorio de trabajo, como el resto del kernel)
        if (!str_starts_with($root, '/')) {
            $root = getcwd() . '/' . $root;
        }

        return rtrim($root, '/') . '/';
    }
}
