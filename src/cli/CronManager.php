<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

use Exception;

class CronManager
{
    private static string $path = 'app/cron/';

    public static function listCrons(): void
    {
        if (!is_dir(self::$path)) {
            CLIErrorHandler::info('No hay tareas cron creadas (directorio no existe).');

            return;
        }

        $files = glob(self::$path . '*.php');
        if (empty($files)) {
            CLIErrorHandler::info('No hay tareas cron creadas.');

            return;
        }

        CLIErrorHandler::info('Tareas cron disponibles:');
        foreach ($files as $file) {
            $name = basename($file, '.php');
            echo " - {$name}\n";
        }
    }

    public static function runCron(string $name): void
    {
        $className = 'app\\cron\\' . ucfirst($name);

        if (!class_exists($className)) {
            CLIErrorHandler::warning("Error: La clase de cron '{$className}' no existe.");

            return;
        }

        if (!method_exists($className, 'run')) {
            CLIErrorHandler::warning("Error: La clase '{$className}' no tiene un método run().");

            return;
        }

        try {
            CLIErrorHandler::info("Iniciando ejecución de {$name}...");
            $startTime = microtime(true);

            (new $className())->run();

            $duration = round(microtime(true) - $startTime, 4);
            CLIErrorHandler::success("Ejecución finalizada en {$duration}s.");
        } catch (Exception $exception) {
            CLIErrorHandler::handleGeneralError($exception, "Ejecutando cron {$name}");
        }
    }
}
