<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;

/** bin/versaCLI: el lanzador global que permite escribir `versaCLI <comando>` sin `php`. */
final class CliLauncherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/versawys_launcher_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/proyecto/versaWYS', 0o775, true);
        mkdir($this->dir . '/proyecto/app/controllers', 0o775, true);
        file_put_contents(
            $this->dir . '/proyecto/versaCLI',
            "<?php echo 'proyecto: ' . implode(' ', array_slice(\$argv, 1));\n",
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testDesdeUnaSubcarpetaEjecutaElVersaCLIDelProyectoConSusArgumentos(): void
    {
        [$code, $out] = $this->launch('/proyecto/app/controllers', 'migrate:up --force');

        self::assertSame(0, $code);
        self::assertSame('proyecto: migrate:up --force', $out);
    }

    public function testFueraDeUnProyectoFallaConUnMensajeClaro(): void
    {
        [$code, $out] = $this->launch('', 'help');

        self::assertSame(1, $code);
        self::assertStringContainsString('no hay un proyecto versaWYS', $out);
    }

    /** @return array{int, string} */
    private function launch(string $cwd, string $args): array
    {
        $launcher = escapeshellarg(__DIR__ . '/../bin/versaCLI');
        exec('cd ' . escapeshellarg($this->dir . $cwd) . " && {$launcher} {$args} 2>&1", $output, $code);

        return [$code, implode("\n", $output)];
    }
}
