<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use versaWYS\kernel\cli\commands\CheckPsr4Command;

/** app:check:psr4: lo que funciona en Windows (sin distinguir mayúsculas) y falla en Linux. */
final class CheckPsr4Test extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/versawys_psr4_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/app/models', 0o775, true);
        mkdir($this->dir . '/app/controllers', 0o775, true);
        mkdir($this->dir . '/app/helpers', 0o775, true);
        file_put_contents($this->dir . '/composer.json', json_encode(['autoload' => ['psr-4' => ['app\\' => 'app/']]]));
        file_put_contents($this->dir . '/app/models/Users.php', "<?php\nnamespace app\\models;\nclass Users {}\n");
        file_put_contents(
            $this->dir . '/app/models/Contrato.php',
            "<?php\nnamespace app\\models;\ninterface Contrato {}\n",
        );
        file_put_contents($this->dir . '/app/helpers/fechas.php', "<?php\nfunction hoy() {}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testUnProyectoCorrectoNoTieneProblemas(): void
    {
        $this->write('app/controllers/Home.php', <<<'PHP'
            namespace app\controllers;

            use app\models\Users;
            use app\models as Models;
            use app\models\Contrato as C;
            use RuntimeException;

            final class Home extends \stdClass implements C, \Countable
            {
                public function index(): void
                {
                    $u = new Users();
                    $m = new Models\Users();
                    Users::class;
                    self::class;
                    $f = function () use ($u) {};
                    require __DIR__ . '/../helpers/fechas.php';
                    throw new RuntimeException();
                }

                public function count(): int { return 0; }
            }
            PHP);

        self::assertSame([], $this->issues());
    }

    public function testDetectaNamespaceYNombreDeArchivoQueNoCalzan(): void
    {
        $this->write('app/controllers/Reportes.php', "namespace app\\Controllers;\nclass Reportes {}\n");
        $this->write('app/controllers/ventas.php', "namespace app\\controllers;\nclass Ventas {}\n");
        $this->write('app/controllers/Otro.php', "namespace app\\controllers;\nclass Distinto {}\n");

        self::assertSame(
            [
                'app/controllers/Otro.php:1 no declara la clase Otro que espera PSR-4 por su nombre',
                'app/controllers/Reportes.php:1 declara app\\Controllers\\Reportes y por su ruta debería ser app\\controllers\\Reportes',
                'app/controllers/ventas.php:1 declara app\\controllers\\Ventas y por su ruta debería ser app\\controllers\\ventas',
            ],
            $this->issues(),
        );
    }

    public function testDetectaReferenciasConOtrasMayusculasOInexistentes(): void
    {
        $this->write('app/controllers/Home.php', <<<'PHP'
            namespace app\controllers;

            use app\models\users;
            use app\Models as M;

            class Home extends \app\models\Base implements \app\models\contrato
            {
                public function index(): void
                {
                    new users();
                    new \app\models\NoExiste();
                    M\Users::class;
                }
            }
            PHP);

        self::assertSame(
            [
                'app/controllers/Home.php:4 usa app\\models\\users y la clase se declara app\\models\\Users: en Linux no se encuentra',
                'app/controllers/Home.php:5 usa app\\Models y la clase se declara app\\models: en Linux no se encuentra',
                'app/controllers/Home.php:7 usa la clase app\\models\\Base, que no existe en el proyecto',
                'app/controllers/Home.php:7 usa app\\models\\contrato y la clase se declara app\\models\\Contrato: en Linux no se encuentra',
                'app/controllers/Home.php:11 usa app\\models\\users y la clase se declara app\\models\\Users: en Linux no se encuentra',
                'app/controllers/Home.php:12 usa la clase app\\models\\NoExiste, que no existe en el proyecto',
                'app/controllers/Home.php:13 usa app\\Models\\Users y la clase se declara app\\models\\Users: en Linux no se encuentra',
            ],
            $this->issues(),
        );
    }

    public function testDetectaRequireConOtrasMayusculasOAUnArchivoInexistente(): void
    {
        $this->write('app/controllers/Home.php', <<<'PHP'
            namespace app\controllers;

            class Home
            {
                public function index(): void
                {
                    require_once __DIR__ . '/../Helpers/fechas.php';
                    include(__DIR__ . '/../helpers/no-existe.php');
                    require $this->dinamico();
                }
            }
            PHP);

        $issues = $this->issues();

        self::assertCount(2, $issues);
        self::assertStringContainsString(
            'Home.php:8 require/include usa «Helpers» y en disco es «helpers»',
            $issues[0],
        );
        self::assertStringContainsString('Home.php:9 require/include a', $issues[1]);
        self::assertStringContainsString('no-existe.php, que no existe', $issues[1]);
    }

    public function testSinMapeosPsr4NoHayNadaQueRevisarYUnMapeoRotoSeInforma(): void
    {
        file_put_contents($this->dir . '/composer.json', '{}');
        self::assertNull(
            (new ReflectionClass(CheckPsr4Command::class))
                ->newInstanceWithoutConstructor()
                ->analyze($this->dir),
        );

        file_put_contents($this->dir . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['lib\\' => 'no-existe/']],
        ]));
        self::assertSame(['composer.json:0 El mapeo lib\\ apunta a no-existe/, que no existe'], $this->issues());
    }

    public function testLocatePathDiceDondeDifierenLasMayusculas(): void
    {
        [$found, $diff] = CheckPsr4Command::locatePath($this->dir . '/APP/models/Users.php');

        self::assertSame(realpath($this->dir) . '/app/models/Users.php', $found);
        self::assertSame(['used' => 'APP', 'expected' => 'app'], $diff);
        self::assertSame([null, null], CheckPsr4Command::locatePath($this->dir . '/app/nada.php'));
    }

    /** @return list<string> "archivo:línea mensaje" */
    private function issues(): array
    {
        $command = (new ReflectionClass(CheckPsr4Command::class))->newInstanceWithoutConstructor();
        $issues = $command->analyze($this->dir);

        return array_map(static fn(array $i): string => "{$i['file']}:{$i['line']} {$i['message']}", $issues ?? []);
    }

    private function write(string $path, string $code): void
    {
        file_put_contents($this->dir . '/' . $path, "<?php\n" . $code);
    }
}
