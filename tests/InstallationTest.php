<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\ConfigManager;
use versaWYS\kernel\Installation;

final class InstallationTest extends TestCase
{
    private string $dir;

    private string $cwd;

    private mixed $originalConfig = null;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $this->cwd = (string) getcwd();
        $this->dir = sys_get_temp_dir() . '/versawys_install_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/versaWYS/config', 0o775, true);
        // Un proyecto subido con su frontend compilado: si no, el primer arranque se detiene en los requisitos
        mkdir($this->dir . '/public/dashboard/css', 0o775, true);
        mkdir($this->dir . '/public/dashboard/js', 0o775, true);
        touch($this->dir . '/public/dashboard/css/dashboard.css');
        touch($this->dir . '/public/dashboard/js/app.js');
        chdir($this->dir);
        putenv('VERSAWYS_CONFIG');
    }

    protected function tearDown(): void
    {
        global $config;

        chdir($this->cwd);
        $config = $this->originalConfig;
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testPendienteMientrasExistaElTokenDeInstalacion(): void
    {
        self::assertFalse(
            Installation::isPending(),
            'sin token (proyecto instalado antes del instalador, o sin instalador)',
        );

        file_put_contents(Installation::tokenFile(), 'tok');
        self::assertTrue(Installation::isPending(), 'recién creado o instalación a medias');

        file_put_contents(Installation::lockFile(), '{}');
        self::assertFalse(Installation::isPending(), 'con installed.lock, siempre instalado');
    }

    public function testConOtroConfigJsonElEstadoDeInstalacionEsElDeEseArchivo(): void
    {
        // El proyecto tiene la instalación pendiente (recién hecho config:init)...
        file_put_contents(Installation::tokenFile(), 'tok');
        self::assertTrue(Installation::isPending());

        // ...pero las pruebas, o un staging, usan otro config.json: su carpeta no tiene token
        mkdir($this->dir . '/pruebas');
        putenv('VERSAWYS_CONFIG=' . $this->dir . '/pruebas/config.json');

        try {
            self::assertFalse(Installation::isPending());
            self::assertSame($this->dir . '/pruebas/install.token', Installation::tokenFile());
        } finally {
            putenv('VERSAWYS_CONFIG');
        }
    }

    public function testElPrimerArranqueCreaLaConfiguracionYElToken(): void
    {
        self::assertFileDoesNotExist(ConfigManager::path());

        Installation::bootstrap();

        self::assertFileExists(ConfigManager::path());
        self::assertFileExists(Installation::tokenFile());
        $config = json_decode((string) file_get_contents(ConfigManager::path()), true);
        self::assertSame(64, strlen((string) $config['session']['key']), 'clave de sesión propia');
        self::assertSame('', $config['database']['database'], 'la base de datos la pide el instalador');

        // Ya creada, no la toca
        $before = file_get_contents(ConfigManager::path());
        Installation::bootstrap();
        self::assertSame($before, file_get_contents(ConfigManager::path()));
    }

    public function testLosRequisitosIncluyenPhpExtensionesYCarpetas(): void
    {
        $labels = array_column(Installation::requirements(), 'label');

        self::assertStringContainsString('PHP 8.3', $labels[0]);
        self::assertContains('intl (Fechas, números e idioma)', $labels);
        self::assertContains('versaWYS/config con permiso de escritura', $labels);
        self::assertContains('Frontend compilado (public/dashboard)', $labels);
    }

    public function testLaPaginaDeRequisitosEscapaLoQueMuestra(): void
    {
        $html = Installation::requirementsPage([[
            'label' => '<script>x</script>',
            'ok' => false,
            'fix' => 'Corrige <b>esto</b>',
        ]], 'falló <img>');

        self::assertStringNotContainsString('<script>x', $html);
        self::assertStringContainsString('&lt;script&gt;x', $html);
        self::assertStringContainsString('falló &lt;img&gt;', $html);
    }
}
