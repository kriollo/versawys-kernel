<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\commands\CheckDeployCommand;

/** app:check:deploy --production: carpetas donde escribe la aplicación, existan o no todavía. */
final class WritableCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/versawys-writable-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' && rm -rf ' . escapeshellarg($this->dir));
    }

    public function testCarpetaExistenteEscribible(): void
    {
        self::assertTrue(CheckDeployCommand::canWrite($this->dir . '/cache'));
    }

    public function testVariosNivelesQueAunNoExistenBajoUnaCarpetaEscribible(): void
    {
        // el primer deploy: cache/ está vacía y Twig crea twig/.cache al renderizar
        self::assertTrue(CheckDeployCommand::canWrite($this->dir . '/cache/twig/.cache'));
    }

    public function testCarpetaSinPermiso(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('root escribe en cualquier carpeta');
        }
        chmod($this->dir . '/cache', 0o555);

        self::assertFalse(CheckDeployCommand::canWrite($this->dir . '/cache'));
        self::assertFalse(CheckDeployCommand::canWrite($this->dir . '/cache/twig/.cache'));
    }
}
