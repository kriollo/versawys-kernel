<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

use Exception;
use PDO;
use PDOException;
use versaWYS\kernel\SystemCheck;

class StatusManager
{
    public static function handleStatus(bool $verbose = false): void
    {
        if ($verbose) {
            CLIErrorHandler::info('Verificando estado del sistema versaWYS');
        }

        echo CLIErrorHandler::colorize('📊 Estado del Sistema versaWYS', 'cyan') . "\n";
        echo str_repeat('═', 50) . "\n\n";

        // Información básica del sistema
        echo CLIErrorHandler::colorize('🖥️  Sistema:', 'yellow') . "\n";
        echo '   PHP: ' . PHP_VERSION . "\n";
        echo '   OS: ' . PHP_OS . ' (' . php_uname('m') . ")\n";
        echo '   SAPI: ' . PHP_SAPI . "\n\n";

        // La misma revisión que el asistente de instalación (/install)
        self::printRequirements();

        // Información del proyecto
        echo CLIErrorHandler::colorize('📁 Proyecto:', 'yellow') . "\n";
        echo '   Directorio: ' . getcwd() . "\n";

        $packageJsonPath = getcwd() . '/package.json';
        if (file_exists($packageJsonPath)) {
            $packageData = json_decode(file_get_contents($packageJsonPath), true);
            if (isset($packageData['version'])) {
                echo '   Versión: ' . $packageData['version'] . "\n";
            }

            if (isset($packageData['name'])) {
                echo '   Nombre: ' . $packageData['name'] . "\n";
            }
        }

        // Verificar configuración
        echo "\n" . CLIErrorHandler::colorize('⚙️  Configuración:', 'yellow') . "\n";
        $configPath = ConfigManager::path();
        if (file_exists($configPath)) {
            echo "   ✅ Archivo de configuración encontrado\n";
            $config = json_decode(file_get_contents($configPath), true);
            if ($config) {
                echo '   Debug: ' . ($config['build']['debug'] ?? false ? '🟢 Activado' : '🔴 Desactivado') . "\n";
                echo '   Cache Twig: ' . ($config['twig']['cache'] ?? false ? '🟢 Activado' : '🔴 Desactivado') . "\n";
            }
        } else {
            echo "   ❌ Archivo de configuración no encontrado\n";
        }

        // Verificar base de datos
        echo "\n" . CLIErrorHandler::colorize('🗄️  Base de Datos:', 'yellow') . "\n";

        try {
            $configPath = ConfigManager::path();
            if (file_exists($configPath)) {
                $config = json_decode(file_get_contents($configPath), true);
                if (isset($config['database'])) {
                    $dbConfig = $config['database'];
                    echo '   Host: ' . ($dbConfig['host'] ?? 'No configurado') . "\n";
                    echo '   Base de datos: ' . ($dbConfig['database'] ?? 'No configurado') . "\n";
                    echo '   Puerto: ' . ($dbConfig['port'] ?? 'No configurado') . "\n";

                    // Intentar conexión
                    if (isset($dbConfig['host'], $dbConfig['database'], $dbConfig['username'])) {
                        echo '   Estado: ';

                        try {
                            // Usar el driver configurado en config.json
                            $driver = $dbConfig['driver'] ?? 'mysql';
                            // Mapear 'postgresql' a 'pgsql' para PDO
                            if ($driver === 'postgresql') {
                                $driver = 'pgsql';
                            }

                            $dsn = sprintf(
                                '%s:host=%s;port=%s;dbname=%s',
                                $driver,
                                $dbConfig['host'],
                                $dbConfig['port'],
                                $dbConfig['database'],
                            );
                            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'] ?? '');
                            echo "🟢 Conectado\n";
                        } catch (PDOException $e) {
                            echo "🔴 Error de conexión\n";
                            if ($verbose) {
                                echo '   Error: ' . $e->getMessage() . "\n";
                            }
                        }
                    }
                } else {
                    echo "   ❌ Configuración de base de datos no encontrada\n";
                }
            }
        } catch (Exception $exception) {
            echo "   ❌ Error verificando base de datos\n";
            if ($verbose) {
                echo '   Error: ' . $exception->getMessage() . "\n";
            }
        }

        // Verificar directorios importantes
        echo "\n" . CLIErrorHandler::colorize('📂 Directorios:', 'yellow') . "\n";
        $directories = [
            'app/controllers' => 'Controladores',
            'app/models' => 'Modelos',
            'app/routes' => 'Rutas',
            'app/middleware' => 'Middleware',
            'app/migrations' => 'Migraciones',
            'app/seeders' => 'Seeders',
            'app/templates' => 'Templates',
            'src/dashboard/js' => 'JavaScript Dashboard',
            'public/dashboard' => 'Assets públicos',
            'versaWYS/logs' => 'Logs',
            'versaWYS/cache' => 'Cache',
        ];

        foreach ($directories as $dir => $name) {
            $status = is_dir($dir) ? '🟢' : '🔴';
            echo "   {$status} {$name} ({$dir})\n";
        }

        // Archivos importantes
        echo "\n" . CLIErrorHandler::colorize('📄 Archivos importantes:', 'yellow') . "\n";
        $files = [
            'index.php' => 'Punto de entrada',
            'versaCLI' => 'CLI versaWYS',
            'package.json' => 'Configuración Node.js',
            'tsconfig.json' => 'Configuración TypeScript',
            'src/dashboard/css/dashboard.css' => 'Entrada de Tailwind 4 (@theme, @source)',
            'versacompile.config.ts' => 'Configuración del compilador',
        ];

        foreach ($files as $file => $name) {
            $status = file_exists($file) ? '🟢' : '🔴';
            echo "   {$status} {$name} ({$file})\n";
        }

        echo "\n" . str_repeat('═', 50) . "\n";
        echo CLIErrorHandler::colorize('Estado del sistema verificado ✓', 'green') . "\n\n";
    }

    /**
     * Requisitos del servidor: los mismos que revisa el asistente web de instalación.
     */
    private static function printRequirements(): void
    {
        $checks = SystemCheck::run(self::readProjectConfig());
        echo CLIErrorHandler::colorize('✅ Requisitos (los mismos que revisa /install):', 'yellow') . "\n";
        $group = '';
        foreach ($checks as $check) {
            if ($check['group'] !== $group) {
                $group = $check['group'];
                echo "   {$group}:\n";
            }

            echo '     ' . ($check['ok'] ? '✓' : '✗') . ' ' . $check['label'] . "\n";
            if (!$check['ok']) {
                echo '       → ' . $check['fix'] . "\n";
            }
        }

        echo
            SystemCheck::passes($checks)
                ? '   ' . CLIErrorHandler::colorize('Todo en orden', 'green') . "\n\n"
                : '   ' . CLIErrorHandler::colorize('Falta algo: corrige lo marcado con ✗', 'red') . "\n\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function readProjectConfig(): array
    {
        $configPath = ConfigManager::path();
        if (!file_exists($configPath)) {
            return [];
        }

        $content = file_get_contents($configPath);
        if (!is_string($content) || $content === '') {
            return [];
        }

        $json = json_decode($content, true);

        return is_array($json) ? $json : [];
    }
}
