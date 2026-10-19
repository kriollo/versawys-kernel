<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use versaWYS\kernel\cli\CommandExecutor;

final class CommandExecutorTest extends TestCase
{
    public function testEveryParameterReachesTheCommandAsASingleLiteralArgument(): void
    {
        $build = new ReflectionMethod(CommandExecutor::class, 'buildParamsString');
        $params = ['a; rm -rf /', '$(id)', "it's", ['nombre' => '`whoami`'], null, true];

        $line = (string) $build->invoke(null, $params);

        // El shell devuelve cada valor tal cual, sin ejecutar nada
        exec('printf "%s\n" ' . $line, $output);
        self::assertSame(['a; rm -rf /', '$(id)', "it's", '{"nombre":"`whoami`"}', '', '1'], $output);
    }

    public function testEnSegundoPlanoUsaLaRutaCompletaDePhpYDejaLaSalidaEnElArchivo(): void
    {
        $dir = sys_get_temp_dir() . '/versawys_exec_' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/versaCLI', "<?php echo 'corrió: ' . implode(' ', array_slice(\$argv, 1));");
        $cwd = (string) getcwd();
        $path = (string) getenv('PATH');
        chdir($dir);
        // Como en cron: sin `php` en el PATH, solo funciona con la ruta completa
        putenv('PATH=/nonexistent');

        try {
            CommandExecutor::runInBackground('schedule:exec', ['abc123'], outputFile: $dir . '/salida.log');
            for ($i = 0; $i < 50 && (!is_file($dir . '/salida.log') || filesize($dir . '/salida.log') === 0); $i++) {
                clearstatcache();
                usleep(100_000);
            }

            self::assertSame(
                'corrió: app:schedule:exec abc123',
                trim((string) file_get_contents($dir . '/salida.log')),
            );
        } finally {
            putenv('PATH=' . $path);
            chdir($cwd);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
