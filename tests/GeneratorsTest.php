<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\ControllerManager;
use versaWYS\kernel\cli\FrontManager;
use versaWYS\kernel\cli\MiddlewareManager;
use versaWYS\kernel\cli\MigrationManager;
use versaWYS\kernel\cli\ModelManager;
use versaWYS\kernel\cli\RouteManager;

/** Lo que genera versaMODULE tiene que ser PHP válido y calzar entre sí (nombres, tabla, vista). */
final class GeneratorsTest extends TestCase
{
    private string $cwd;

    private string $dir;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->dir = sys_get_temp_dir() . '/versawys-gen-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testVueModuleFilesAreValidAndConsistent(): void
    {
        ob_start();
        RouteManager::createRoute('NotasRapidas');
        ControllerManager::createController('NotasRapidas', 'vue');
        ModelManager::createModel('NotasRapidas');
        MiddlewareManager::createMiddleware('NotasRapidas');
        MigrationManager::createMigration('NotasRapidas');
        FrontManager::createVue('NotasRapidas', 'dash');
        ob_end_clean();

        $php = [
            'app/routes/NotasRapidasRoutes.php',
            'app/controllers/NotasRapidasController.php',
            'app/models/NotasRapidas.php',
            'app/middleware/NotasRapidasMiddleware.php',
            ...glob('app/migrations/m*_NotasRapidas.php'),
        ];
        self::assertCount(5, $php);
        foreach ($php as $archivo) {
            exec('php -l ' . escapeshellarg($archivo) . ' 2>&1', $salida, $codigo);
            self::assertSame(0, $codigo, $archivo . ': ' . implode("\n", $salida));
        }

        $rutas = (string) file_get_contents('app/routes/NotasRapidasRoutes.php');
        self::assertStringContainsString('use app\middleware\NotasRapidasMiddleware;', $rutas);
        self::assertStringContainsString("'/admin/notasrapidas'", $rutas);
        self::assertStringContainsString("'/list', [NotasRapidasController::class, 'list']", $rutas);

        // La vista que renderiza el controlador es el componente que generó front:makeVue
        $controlador = (string) file_get_contents('app/controllers/NotasRapidasController.php');
        self::assertStringContainsString("'m' => 'dashboard/js/notasrapidas/dashNotasRapidas'", $controlador);
        self::assertFileExists('src/dashboard/js/notasrapidas/dashNotasRapidas.vue');
        // El componente llama a la API que genera versaMODULE (Scaffold::module): GET .../api/notasrapidas
        self::assertStringContainsString(
            '/notasrapidas/api/notasrapidas',
            (string) file_get_contents('src/dashboard/js/notasrapidas/dashNotasRapidas.vue'),
        );

        // Modelo y migración apuntan a la misma tabla
        self::assertStringContainsString("'notas_rapidas'", (string) file_get_contents('app/models/NotasRapidas.php'));
        self::assertStringContainsString("create('notas_rapidas'", (string) file_get_contents($php[4]));
    }

    public function testTwigControllerRendersTheGeneratedTemplate(): void
    {
        ob_start();
        ControllerManager::createController('Reportes', 'twig');
        FrontManager::createTwig('Reportes', 'dash');
        ob_end_clean();

        self::assertStringContainsString(
            "render('dashboard/reportes/dashReportes')",
            (string) file_get_contents('app/controllers/ReportesController.php'),
        );
        self::assertFileExists('app/templates/dashboard/reportes/dashReportes.twig');
    }
}
