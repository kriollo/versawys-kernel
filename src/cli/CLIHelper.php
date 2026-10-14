<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

use RuntimeException;

class CLIHelper
{
    /** false con --no-interaction (-n): ask() y confirm() responden su valor por defecto sin leer la entrada. */
    public static bool $interactive = true;

    /**
     * Carpeta del composer.json que instaló el vendor en uso: la raíz en proyectos creados con
     * composer create-project, o versaWYS/ en los anteriores. Se le pregunta a Composer, porque
     * un proyecto puede tener otro composer.json solo para scripts de herramientas.
     */
    public static function composerDir(): string
    {
        $path = realpath(\Composer\InstalledVersions::getRootPackage()['install_path']);

        return $path !== false ? $path : getcwd() . '/versaWYS';
    }

    /**
     * Ask a question and return the user's input.
     */
    public static function ask(string $question, string $default = ''): string
    {
        echo CLIErrorHandler::colorize($question, 'green');
        if ($default !== '') {
            echo CLIErrorHandler::colorize(" [{$default}]", 'yellow');
        }
        echo ': ';

        if (!self::$interactive) {
            // Automatización (deploy, CI, otro proceso): nunca quedar esperando una respuesta
            echo $default . PHP_EOL;

            return $default;
        }

        $handle = fopen('php://stdin', 'r');
        $line = fgets($handle);
        fclose($handle);

        $input = trim((string) $line);

        if ($input === '' && $default !== '') {
            return $default;
        }

        return $input;
    }

    /**
     * Ask for confirmation (yes/no).
     */
    public static function confirm(string $question, bool $default = false): bool
    {
        $defaultStr = $default ? 'y' : 'n';
        $prompt = "{$question} (y/n)";

        $input = strtolower(self::ask($prompt, $defaultStr));

        return in_array($input, ['y', 'yes', 'si', 's'], true);
    }

    /**
     * Ensure a directory exists.
     */
    public static function ensureDirectory(string $path, int $permissions = 0o775): void
    {
        if (!is_dir($path)) {
            if (!mkdir($path, $permissions, true) && !is_dir($path)) {
                throw new RuntimeException("No se pudo crear el directorio: {$path}");
            }
        }
    }
}
