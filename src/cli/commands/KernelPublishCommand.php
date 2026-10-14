<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use FilesystemIterator;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\cli\CLIErrorHandler;

/**
 * Copia al proyecto los archivos base que trae el kernel (index.php, versaCLI, .htaccess, ejemplos
 * de Apache y nginx). Sin --force solo crea los que faltan y muestra en qué difieren los demás:
 * un .htaccess suele tener reglas propias del proyecto.
 */
class KernelPublishCommand extends BaseCommand
{
    /**
     * Archivos base del kernel, relativos a la raíz del proyecto.
     *
     * @return array<string, string> ruta en el proyecto => ruta del original en el kernel
     */
    public static function stubs(): array
    {
        $dir = dirname(__DIR__, 3) . '/stubs';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $dir,
            FilesystemIterator::SKIP_DOTS,
        )) as $file) {
            $files[substr($file->getPathname(), strlen($dir) + 1)] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        $force = in_array('--force', [...$params, ...($_SERVER['argv'] ?? [])], true);
        $pending = 0;

        foreach (self::stubs() as $target => $source) {
            if (is_file($target) && file_get_contents($target) === file_get_contents($source)) {
                echo CLIErrorHandler::colorize('  ✓ ', 'green') . "{$target}\n";

                continue;
            }

            if (!is_file($target) || $force) {
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0o775, true);
                }

                $created = !is_file($target);
                copy($source, $target);
                echo
                    CLIErrorHandler::colorize($created ? '  + ' : '  ↻ ', 'cyan')
                        . $target
                        . ($created ? ' (creado)' : ' (reemplazado)')
                        . "\n";

                continue;
            }

            $pending++;
            echo CLIErrorHandler::colorize('  ≠ ', 'yellow') . "{$target} difiere de la versión del kernel:\n";
            exec('diff -u ' . escapeshellarg($target) . ' ' . escapeshellarg($source) . ' 2>/dev/null', $diff);
            echo implode("\n", array_slice($diff, 2, 40)) . (count($diff) > 42 ? "\n    …" : '') . "\n\n";
            $diff = [];
        }

        if ($pending > 0) {
            CLIErrorHandler::info(
                "{$pending} archivo(s) con cambios propios: revisar el diff (- proyecto, + kernel) y usar --force para reemplazarlos.",
            );
        }

        return 0;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Crea o actualiza index.php, versaCLI, .htaccess y los ejemplos de configs/ desde el kernel';
    }

    #[Override]
    public function getHelp(): string
    {
        return (
            "Uso: php versaCLI app:kernel:publish [--force]\n\n"
            . "Sin opciones crea los archivos que faltan y muestra en qué difieren los demás (- proyecto, + kernel).\n"
            . '--force reemplaza los que difieren. Archivos: '
            . implode(', ', array_keys(self::stubs()))
            . "\n"
        );
    }
}
