<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

use RuntimeException;

/**
 * Generadores de código desde las plantillas de resources/generators.
 *
 * - module(): un módulo completo aislado por empresa (migración con id_empresa, modelo, FormRequest,
 *   controlador con inyección y Tenant, rutas documentadas y su prueba con TestClient).
 * - request(), job(), test(), cron(): un archivo suelto.
 */
final class Scaffold
{
    /**
     * @return list<string> los archivos creados
     */
    public static function module(string $name, string $front = 'vue'): array
    {
        $vars = self::vars($name);
        $vars['{{migration}}'] = 'm' . date('YmdHis') . '_' . $vars['{{Name}}'];
        $vars['{{index}}'] = $front === 'twig'
            ? "    public function index(): string\n    {\n        return \$this->template->render('dashboard/{$vars['{{url}}']}/dash{$vars['{{Name}}']}');\n    }"
            : "    public function index(): string\n    {\n        return \$this->template->render('dashboard/loader', [\n            'm' => 'dashboard/js/{$vars['{{url}}']}/dash{$vars['{{Name}}']}',\n        ]);\n    }";

        $files = [
            "app/migrations/{$vars['{{migration}}']}.php" => 'module/migration',
            "app/models/{$vars['{{Name}}']}.php" => 'module/model',
            "app/requests/{$vars['{{Name}}']}Request.php" => 'module/request',
            "app/controllers/{$vars['{{Name}}']}Controller.php" => 'module/controller',
            "app/routes/{$vars['{{Name}}']}Routes.php" => 'module/routes',
            "versaWYS/tests/{$vars['{{Name}}']}Test.php" => 'module/test',
        ];

        $created = [];
        foreach ($files as $path => $stub) {
            if (!self::write($path, self::render($stub, $vars))) {
                continue;
            }

            $created[] = $path;
        }

        return $created;
    }

    public static function request(string $name): bool
    {
        $vars = self::vars($name);

        return self::write("app/requests/{$vars['{{Name}}']}.php", self::render('request', $vars));
    }

    public static function job(string $name): bool
    {
        $vars = self::vars($name);

        return self::write("app/jobs/{$vars['{{Name}}']}.php", self::render('job', $vars));
    }

    public static function test(string $name): bool
    {
        $vars = self::vars($name);
        $file = str_ends_with($vars['{{Name}}'], 'Test') ? $vars['{{Name}}'] : $vars['{{Name}}'] . 'Test';

        return self::write("versaWYS/tests/{$file}.php", self::render('test', $vars));
    }

    public static function cron(string $name): bool
    {
        $vars = self::vars($name);

        return self::write("app/cron/{$vars['{{Name}}']}.php", self::render('cron', $vars));
    }

    /**
     * @param array<string, string> $vars
     */
    public static function render(string $stub, array $vars): string
    {
        $file = dirname(__DIR__, 2) . "/resources/generators/{$stub}.stub";
        $template = is_file($file) ? file_get_contents($file) : false;
        if ($template === false) {
            throw new RuntimeException("No existe la plantilla {$stub}");
        }

        return strtr($template, $vars);
    }

    /**
     * Nombre en las formas que usan las plantillas: OrdenesCompra → clase OrdenesCompra, URL y carpeta
     * ordenescompra (como los demás generadores), tabla ordenes_compra.
     *
     * @return array<string, string>
     */
    private static function vars(string $name): array
    {
        $class = ucfirst($name);

        return [
            '{{Name}}' => $class,
            '{{name}}' => strtolower($class),
            '{{url}}' => strtolower($class),
            '{{table}}' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class)),
        ];
    }

    private static function write(string $path, string $contents): bool
    {
        if (is_file($path) && !CLIHelper::confirm("{$path} ya existe. ¿Sobrescribirlo?")) {
            CLIErrorHandler::info("Se mantiene {$path}");

            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("No se pudo crear {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("No se pudo escribir {$path}");
        }

        CLIErrorHandler::success("Creado {$path}");

        return true;
    }
}
