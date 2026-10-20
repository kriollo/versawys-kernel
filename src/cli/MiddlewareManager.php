<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

class MiddlewareManager
{
    private static string $path = 'app/middleware/';

    public static function createMiddleware(string $middlewareName): void
    {
        $middlewareName = ucfirst($middlewareName) . 'Middleware';

        CLIErrorHandler::info("Creando middleware {$middlewareName}...");
        $middlewareFile = self::$path . ($middlewareName . '.php');

        if (file_exists($middlewareFile)) {
            if (!CLIHelper::confirm("El Middleware {$middlewareFile} ya existe. Desea sobreescribirlo?")) {
                CLIErrorHandler::info('Operación cancelada.');
                exit(1);
            }

            unlink($middlewareFile);
        }

        $template = <<<'EOT'
            <?php

            declare(strict_types=1);

            namespace app\middleware;

            use versaWYS\kernel\helpers\Functions;

            class $middlewareName
            {
                // Se declara en la ruta: [$middlewareName::class, 'validar']. Un arreglo con `code` corta la
                // petición y responde ese estado; `true` la deja seguir. Reglas: documentacion/Validacion.md
                public function validar(): true|array
                {
                    global $request;

                    $errores = Functions::validateParams($request->getAllParams(), [
                        'nombre' => 'required|max:120',
                    ]);
                    if ($errores !== []) {
                        return ['success' => 0, 'message' => 'Los datos enviados no son correctos', 'errors' => $errores, 'code' => 422];
                    }

                    return true;
                }
            }

            EOT;

        $template = str_replace('$middlewareName', $middlewareName, $template);

        CLIHelper::ensureDirectory(dirname($middlewareFile));

        if (file_put_contents($middlewareFile, $template)) {
            chmod($middlewareFile, 0o775);
            CLIErrorHandler::success("Middleware {$middlewareFile} creada.");
        } else {
            CLIErrorHandler::warning("No se pudo crear el archivo: {$middlewareFile}");
        }
    }

    public static function deleteMiddleware(string $middlewareName): void
    {
        $middlewareName = ucfirst($middlewareName) . 'Middleware';

        CLIErrorHandler::info("Eliminando middleware {$middlewareName}...");
        $middlewareFile = self::$path . ($middlewareName . '.php');

        if (!file_exists($middlewareFile)) {
            CLIErrorHandler::warning("El Middleware {$middlewareFile} no existe.");
            exit(1);
        }

        if (!CLIHelper::confirm("¿Eliminar el middleware {$middlewareFile}?")) {
            CLIErrorHandler::info('Operación cancelada. Use --force para omitir esta confirmación.');
            exit(1);
        }

        if (unlink($middlewareFile)) {
            CLIErrorHandler::success("Middleware {$middlewareFile} eliminada.");
        } else {
            CLIErrorHandler::warning("No se pudo eliminar: {$middlewareFile}");
        }
    }
}
