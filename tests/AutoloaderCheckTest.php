<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\commands\CheckDeployCommand;
use versaWYS\kernel\Router;

/** app:check:deploy avisa si el autoloader de Composer no tiene mapa de clases (--optimize-autoloader). */
final class AutoloaderCheckTest extends TestCase
{
    private string $vendor;

    protected function setUp(): void
    {
        $this->vendor = sys_get_temp_dir() . '/versawys-autoload-' . bin2hex(random_bytes(4)) . '/vendor';
        mkdir($this->vendor . '/composer', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->vendor)));
    }

    public function testSinMapaDeClasesNoEstaOptimizado(): void
    {
        // Lo que deja `composer install` sin -o: solo las clases de Composer
        $this->classmap(['Composer\\InstalledVersions' => '/x/InstalledVersions.php']);

        self::assertFalse(CheckDeployCommand::autoloaderOptimized($this->vendor));
    }

    public function testConElKernelEnElMapaEstaOptimizado(): void
    {
        $this->classmap([Router::class => '/x/Router.php']);

        self::assertTrue(CheckDeployCommand::autoloaderOptimized($this->vendor));
    }

    public function testSinVendorNoHayNadaQueAvisarAca(): void
    {
        self::assertTrue(CheckDeployCommand::autoloaderOptimized($this->vendor . '/no-existe'));
    }

    /** @param array<string, string> $map */
    private function classmap(array $map): void
    {
        file_put_contents(
            $this->vendor . '/composer/autoload_classmap.php',
            '<?php return ' . var_export($map, true) . ';',
        );
    }
}
