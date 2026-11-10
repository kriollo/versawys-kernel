<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Twig\TwigFilter;
use versaWYS\kernel\Profiler;
use versaWYS\kernel\versaTwig;

final class TwigTest extends TestCase
{
    private string $dir;

    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $this->dir = sys_get_temp_dir() . '/versawys-twig-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/tpl', 0o777, true);
        file_put_contents(
            $this->dir . '/tpl/ficha.twig',
            '<p>{{ nombre }}</p><input value="{{ nombre }}"><div>{{ html|raw }}</div><script nonce="{{ csp_nonce() }}"></script>',
        );
    }

    protected function tearDown(): void
    {
        global $config;

        exec('rm -rf ' . escapeshellarg($this->dir));
        $config = $this->originalConfig;
    }

    public function testWhatAUserWroteIsEscapedEvenIfConfigAsksOtherwise(): void
    {
        // twig.auto_escape en false no tiene efecto: el escape no se apaga por configuración
        $html = $this->twig(false)->renderDirect('ficha', [
            'nombre' => '"><script>alert(1)</script>',
            'html' => '<b>propio</b>',
        ]);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('value="&quot;&gt;', $html);
        // |raw es la única salida sin escape, y queda a cargo de quien la usa
        self::assertStringContainsString('<div><b>propio</b></div>', $html);
    }

    public function testCspNonceIsEmptyUnlessEnabled(): void
    {
        self::assertStringContainsString('<script nonce="">', $this->twig(false)->renderDirect('ficha', [
            'nombre' => '',
            'html' => '',
        ]));
        self::assertMatchesRegularExpression('/<script nonce="[A-Za-z0-9+\/=]{24}">/', $this->twig(
            true,
        )->renderDirect('ficha', ['nombre' => '', 'html' => '']));
    }

    public function testEnDebugLaBarraLaDibujaElProfilerYNoSeDuplica(): void
    {
        global $config;

        $twig = $this->twig(false);
        $config['build']['debug'] = true;
        $config['framework'] = 'versaWYS-PHP';
        $debug = new ReflectionMethod($twig, 'debug');

        // Sin profiler (apagado o en consola), versaTwig sigue dibujando su barra
        Profiler::reset();
        self::assertStringContainsString('Modo Debug: Activado', (string) $debug->invoke($twig));

        // Con el profiler midiendo la petición, la barra es la suya: versaTwig no agrega otra
        $config['profiler']['dir'] = $this->dir . '/profiler';
        Profiler::begin();

        try {
            self::assertSame('', $debug->invoke($twig));
        } finally {
            Profiler::reset();
        }
    }

    /**
     * CVE-2026-46634 (escape del sandbox de Twig con template_from_string): el kernel no carga
     * StringLoaderExtension ni el sandbox, así que un texto del usuario nunca se compila como plantilla.
     */
    public function testUserTextIsNeverCompiledAsATemplate(): void
    {
        file_put_contents($this->dir . '/tpl/eco.twig', '<p>{{ texto }}</p>');
        $twig = $this->twig(false);

        $html = $twig->renderDirect('eco', ['texto' => "{{ 7*7 }}{% include '/etc/passwd' %}{{ _self }}"]);
        self::assertStringContainsString('{{ 7*7 }}', $html);
        self::assertStringNotContainsString('49', $html);

        $env = $twig->init($GLOBALS['config']);
        self::assertFalse($env->hasExtension(\Twig\Extension\StringLoaderExtension::class));
        self::assertFalse($env->hasExtension(\Twig\Extension\SandboxExtension::class));
        self::assertNull($env->getFunction('template_from_string'));
    }

    /** Una API JSON, /up o un redirect no renderizan: crear versaTwig no debe costar nada */
    public function testTwigSeCreaRecienAlRenderizar(): void
    {
        $twig = $this->twig(false);

        self::assertNull((new ReflectionProperty(versaTwig::class, 'twigEnvironment'))->getValue($twig));
        self::assertDirectoryDoesNotExist($this->dir . '/cache', 'la carpeta de caché se revisa al renderizar');

        $twig->renderDirect('ficha', ['nombre' => 'Ana', 'html' => '']);

        self::assertDirectoryExists($this->dir . '/cache');
    }

    public function testLoAgregadoAntesDelPrimerRenderLlegaALaPlantilla(): void
    {
        file_put_contents($this->dir . '/tpl/globales.twig', '{{ empresa }}|{{ "x"|gritar }}');
        $twig = $this->twig(false);
        $twig->addGlobal('empresa', 'Uno');
        $twig->addFilter(new TwigFilter('gritar', static fn(string $s): string => strtoupper($s) . '!'));

        self::assertSame('Uno|X!', $twig->renderDirect('globales'));
        // Después de creado el entorno, se aplica directo
        $twig->addGlobal('empresa', 'Dos');
        self::assertSame('Dos|X!', $twig->renderDirect('globales'));
    }

    public function testEachReleaseCompilesItsOwnTemplatesInTheSharedCache(): void
    {
        // dos releases con la misma plantilla y distinto texto, y una caché compartida
        // (como en el servidor: cada petición corre dentro de su release, con rutas relativas)
        global $config;
        $cwd = (string) getcwd();
        foreach (['viejo', 'nuevo'] as $release) {
            mkdir($this->dir . '/' . $release . '/tpl', 0o777, true);
            file_put_contents($this->dir . '/' . $release . '/tpl/login.twig', '<h2>' . $release . '</h2>');
            chdir($this->dir . '/' . $release);
            $config = [
                'twig' => [
                    'cache' => true,
                    'compiled_dir' => $this->dir . '/cache',
                    'templates_dir' => './tpl',
                    'strict_variables' => false,
                ],
                'build' => ['debug' => false, 'charset' => 'utf-8'],
                'csp' => ['nonce' => false],
            ];
            $twig = new versaTwig($config);
            // compila sin cargar la clase: en el servidor cada petición es un proceso nuevo
            $env = (new ReflectionMethod($twig, 'environment'))->invoke($twig);
            $source = $env->getLoader()->getSourceContext('login.twig');
            $env->getCache(false)->write(
                $env->getCache(false)->generateKey('login.twig', $env->getTemplateClass('login.twig')),
                $env->compileSource($source),
            );
            chdir($cwd);
        }

        $compiled = array_map('file_get_contents', (array) glob($this->dir . '/cache/{,*/}*/*.php', GLOB_BRACE));
        self::assertCount(2, $compiled);
        self::assertCount(1, array_filter($compiled, static fn(string $php): bool => str_contains($php, '<h2>nuevo</h2>')));
    }

    private function twig(bool $nonce): versaTwig
    {
        global $config;
        $config = [
            'twig' => [
                'cache' => false,
                'compiled_dir' => $this->dir . '/cache',
                'templates_dir' => $this->dir . '/tpl',
                'strict_variables' => false,
                'auto_escape' => false,
            ],
            'build' => ['debug' => false, 'charset' => 'utf-8'],
            'csp' => ['nonce' => $nonce],
        ];

        return new versaTwig($config);
    }
}
