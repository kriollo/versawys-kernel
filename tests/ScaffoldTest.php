<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\Scaffold;

/** Lo que generan versaMODULE, RCMD, request:make, job:make, test:make y cron:make. */
final class ScaffoldTest extends TestCase
{
    private string $cwd;

    private string $dir;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->dir = sys_get_temp_dir() . '/versawys-scaffold-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testElModuloSaleAisladoPorEmpresaYSusPiezasCalzan(): void
    {
        ob_start();
        $files = Scaffold::module('OrdenesCompra', 'vue');
        ob_end_clean();

        self::assertCount(7, $files);
        foreach ($files as $file) {
            self::assertValidPhp($file);
        }

        $read =
            static fn(string $suffix): string => (string) file_get_contents((string) current(array_filter($files, static fn(string $f): bool => str_ends_with(
                $f,
                $suffix,
            ))));

        // La tabla tiene la empresa y el modelo filtra por ella en cada consulta
        self::assertStringContainsString("create('ordenes_compra'", $read('_OrdenesCompra.php'));
        self::assertStringContainsString("foreignId('id_empresa')", $read('_OrdenesCompra.php'));
        $model = $read('models/OrdenesCompra.php');
        self::assertSame(5, substr_count($model, "'id_empresa'"), 'listar, buscar, crear, actualizar y eliminar');

        // El controlador usa la empresa de la sesión, la FormRequest y el modelo inyectado
        $controller = $read('OrdenesCompraController.php');
        self::assertStringContainsString('Tenant::id()', $controller);
        self::assertStringContainsString('crear(OrdenesCompraRequest $datos)', $controller);
        self::assertStringContainsString('private readonly OrdenesCompra $modelo', $controller);
        self::assertStringContainsString("'m' => 'dashboard/js/ordenescompra/dashOrdenesCompra'", $controller);

        // Las rutas documentan cada parámetro (route:check) y la prueba usa las mismas URL
        $routes = $read('OrdenesCompraRoutes.php');
        self::assertSame(3, substr_count($routes, '// path-param name:id type:int'));
        self::assertStringContainsString("'/admin/ordenescompra/api/ordenescompra'", $read('OrdenesCompraTest.php'));
        self::assertStringContainsString("Factory::define('ordenes_compra'", $read('factories/OrdenesCompra.php'));
    }

    public function testLosArchivosSueltosSonPhpValido(): void
    {
        ob_start();
        Scaffold::request('GuardarCliente');
        Scaffold::job('EnviarBienvenida');
        Scaffold::test('Clientes');
        Scaffold::cron('Resumen');
        ob_end_clean();

        self::assertValidPhp('app/requests/GuardarCliente.php');
        self::assertValidPhp('app/jobs/EnviarBienvenida.php');
        self::assertValidPhp('versaWYS/tests/ClientesTest.php');
        self::assertValidPhp('app/cron/Resumen.php');
        self::assertStringContainsString('implements Job', (string) file_get_contents('app/jobs/EnviarBienvenida.php'));
        // La tarea programada ya no abre otra conexión a la base de datos
        self::assertStringNotContainsString('OrmFactory', (string) file_get_contents('app/cron/Resumen.php'));
    }

    private static function assertValidPhp(string $file): void
    {
        self::assertFileExists($file);
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, $file . ': ' . implode("\n", $output));
    }
}
