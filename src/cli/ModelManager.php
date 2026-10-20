<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

class ModelManager
{
    private static string $path = 'app/models/';

    public static function createModel(string $modelName): void
    {
        $originalModelName = CLIHelper::tableName($modelName);
        $modelName = ucfirst($modelName);

        CLIErrorHandler::info("Creando modelo {$modelName}...");
        $modelFile = self::$path . ($modelName . '.php');

        if (file_exists($modelFile)) {
            if (!CLIHelper::confirm("El modelo {$modelFile} ya existe. Desea sobreescribirlo?")) {
                CLIErrorHandler::info('Operación cancelada.');
                exit(1);
            }

            unlink($modelFile);
        }

        $template = <<<'EOT'
            <?php

            declare(strict_types=1);

            namespace app\models;

            use versaWYS\kernel\model\VersaORMModel;

            class $modelName extends VersaORMModel
            {
                protected string $table = '$originalModelName';

                public function all(): array
                {
                    return $this->qb()->orderBy('id', 'desc')->getAll();
                }
            }

            EOT;

        $template = str_replace('$modelName', $modelName, $template);
        $template = str_replace('$originalModelName', $originalModelName, $template);

        CLIHelper::ensureDirectory(dirname($modelFile));

        if (file_put_contents($modelFile, $template)) {
            chmod($modelFile, 0o775);
            CLIErrorHandler::success("Modelo {$modelFile} creado.");
        } else {
            CLIErrorHandler::warning("No se pudo crear el archivo: {$modelFile}");
        }
    }

    public static function deleteModel(string $modelName): void
    {
        $modelName = ucfirst($modelName);

        CLIErrorHandler::info("Eliminando modelo {$modelName}...");
        $modelFile = self::$path . ($modelName . '.php');

        if (!file_exists($modelFile)) {
            CLIErrorHandler::warning("El modelo {$modelFile} no existe.");
            exit(1);
        }

        if (unlink($modelFile)) {
            CLIErrorHandler::success("Modelo {$modelFile} eliminado.");
        } else {
            CLIErrorHandler::warning("No se pudo eliminar: {$modelFile}");
        }
    }
}
