<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use Override;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\cli\CLIErrorHandler;
use versaWYS\kernel\cli\CLIHelper;

/**
 * Lo que funciona en Windows (sin distinguir mayúsculas) y falla en Linux: clases cuyo namespace o
 * archivo no calza con su mapeo PSR-4, referencias a clases del proyecto con otras mayúsculas o que
 * no existen, y `require`/`include` a rutas que difieren en mayúsculas de lo que hay en disco.
 *
 * Que los métodos llamados existan lo revisan phpstan y mago (`composer check`).
 */
class CheckPsr4Command extends BaseCommand
{
    /** @var array<string, string> fqcn en minúsculas => como se declara */
    private array $declared = [];

    /** @var array<string, string> namespaces con clases, en minúsculas => como se declaran */
    private array $namespaces = [];

    /** @var list<string> prefijos PSR-4 del proyecto, en minúsculas */
    private array $prefixes = [];

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params): int
    {
        $issues = $this->analyze(CLIHelper::composerDir());
        if ($issues === null) {
            CLIErrorHandler::info('No se detectaron mapeos PSR-4 en composer.json');

            return 0;
        }

        if ($issues === []) {
            CLIErrorHandler::success(
                'Sin inconsistencias PSR-4: clases, namespaces, referencias y rutas calzan con lo que hay en disco.',
            );

            return 0;
        }

        echo CLIErrorHandler::colorize('Se encontraron ' . count($issues) . ' inconsistencia(s):', 'red') . "\n";
        foreach ($issues as $issue) {
            echo
                '  '
                    . CLIErrorHandler::colorize($issue['file'] . ':' . $issue['line'], 'yellow')
                    . '  '
                    . $issue['message']
                    . "\n";
        }

        return 1;
    }

    /**
     * Revisa el proyecto del composer.json de esa carpeta. null si no tiene mapeos PSR-4.
     *
     * @return list<array{file: string, line: int, message: string}>|null
     */
    public function analyze(string $composerDir): ?array
    {
        $json = is_file($composerDir . '/composer.json') ? file_get_contents($composerDir . '/composer.json') : false;
        $composer = $json === false ? null : json_decode($json, true);
        $mappings = is_array($composer) ? (array) ($composer['autoload']['psr-4'] ?? []) : [];
        if ($mappings === []) {
            return null;
        }

        $issues = [];
        $parsed = [];
        foreach ($mappings as $prefix => $path) {
            $dir = realpath($composerDir . '/' . $path);
            if ($dir === false || !is_dir($dir)) {
                $issues[] = [
                    'file' => 'composer.json',
                    'line' => 0,
                    'message' => "El mapeo {$prefix} apunta a {$path}, que no existe",
                ];

                continue;
            }

            $this->prefixes[] = strtolower((string) $prefix);
            foreach (self::phpFiles($dir) as $file) {
                $info = self::parse($file);
                $parsed[$file] = $info;
                foreach ($info['types'] as $type) {
                    $fqcn = ltrim($info['namespace'] . '\\' . $type, '\\');
                    $this->declared[strtolower($fqcn)] = $fqcn;
                }

                // `use app\models as Models;` importa un namespace: también se revisa
                $parts = array_filter(explode('\\', $info['namespace']), static fn(string $p): bool => $p !== '');
                for (; $parts !== []; array_pop($parts)) {
                    $this->namespaces[strtolower(implode('\\', $parts))] = implode('\\', $parts);
                }

                $issues = [...$issues, ...self::checkStructure($file, $info, (string) $prefix, $dir)];
            }
        }

        foreach ($parsed as $file => $info) {
            foreach ($info['references'] as [$fqcn, $line]) {
                $message = $this->checkReference($fqcn);
                if ($message !== null) {
                    $issues[] = ['file' => $file, 'line' => $line, 'message' => $message];
                }
            }

            foreach ($info['includes'] as [$path, $line]) {
                [$found, $mismatch] = self::locatePath($path);
                $message = match (true) {
                    $found === null => "require/include a {$path}, que no existe",
                    $mismatch !== null
                        => "require/include usa «{$mismatch['used']}» y en disco es «{$mismatch['expected']}»",
                    default => null,
                };
                if ($message !== null) {
                    $issues[] = ['file' => $file, 'line' => $line, 'message' => $message];
                }
            }
        }

        $root = realpath($composerDir);
        $root = $root === false ? $composerDir : $root;

        return array_map(
            static fn(array $issue): array => ['file' => self::relative($issue['file'], $root)] + $issue,
            $issues,
        );
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Clases, namespaces, referencias y rutas que funcionan en Windows y fallan en Linux (PSR-4)';
    }

    #[Override]
    public function getHelp(): string
    {
        return <<<'HELP'
            Uso: php versaCLI app:check:psr4

            Revisa lo que funciona en Windows, que no distingue mayúsculas, y falla en Linux:
              - el namespace y el nombre de cada clase calzan con su archivo y su mapeo PSR-4;
              - las clases del proyecto usadas en use, new, Clase::, extends e implements existen
                con esas mayúsculas exactas;
              - las rutas de require/include coinciden en mayúsculas con lo que hay en disco.

            Que los métodos llamados existan lo revisan phpstan y mago (composer check).

            Código de salida: 0 sin problemas, 1 con inconsistencias.
            HELP;
    }

    /**
     * Busca una ruta sin distinguir mayúsculas y dice dónde difiere de lo que hay en disco.
     *
     * @return array{0: ?string, 1: ?array{used: string, expected: string}}
     */
    public static function locatePath(string $path): array
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $current = preg_match('/^[A-Za-z]:$/', $segments[0]) === 1 ? array_shift($segments) : '';
        $mismatch = null;

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                $current = dirname($current === '' ? '/' : $current);
                $current = $current === '/' ? '' : $current;
                continue;
            }

            $entries = is_dir($current === '' ? '/' : $current) ? scandir($current === '' ? '/' : $current) : false;
            if ($entries === false) {
                return [null, null];
            }

            if (!in_array($segment, $entries, true)) {
                $matches = array_values(array_filter(
                    $entries,
                    static fn(string $e): bool => strcasecmp($e, $segment) === 0,
                ));
                if ($matches === []) {
                    return [null, null];
                }

                $mismatch ??= ['used' => $segment, 'expected' => $matches[0]];
                $segment = $matches[0];
            }

            $current .= '/' . $segment;
        }

        return [$current, $mismatch];
    }

    /** Una referencia a una clase del proyecto: ¿existe con esas mayúsculas? */
    private function checkReference(string $fqcn): ?string
    {
        $lower = strtolower($fqcn);
        if (array_filter($this->prefixes, static fn(string $p): bool => str_starts_with($lower, $p)) === []) {
            return null;
        }

        $declared = $this->declared[$lower] ?? $this->namespaces[$lower] ?? null;

        return match (true) {
            $declared === null => "usa la clase {$fqcn}, que no existe en el proyecto",
            $declared !== $fqcn => "usa {$fqcn} y la clase se declara {$declared}: en Linux no se encuentra",
            default => null,
        };
    }

    /**
     * El archivo que declara una clase debe llamarse como ella y estar en la carpeta de su namespace.
     *
     * @param array{namespace: string, types: list<string>} $info
     *
     * @return list<array{file: string, line: int, message: string}>
     */
    private static function checkStructure(string $file, array $info, string $prefix, string $dir): array
    {
        if ($info['types'] === []) {
            return [];
        }

        $relative = substr($file, strlen($dir) + 1, -4);
        $expected = rtrim($prefix, '\\') . '\\' . str_replace('/', '\\', $relative);
        $base = basename($relative);

        foreach ($info['types'] as $type) {
            if (strcasecmp($type, $base) !== 0) {
                continue;
            }

            $declared = ltrim($info['namespace'] . '\\' . $type, '\\');

            return (
                $declared === $expected
                    ? []
                    : [[
                        'file' => $file,
                        'line' => 1,
                        'message' => "declara {$declared} y por su ruta debería ser {$expected}",
                    ]]
            );
        }

        return [[
            'file' => $file,
            'line' => 1,
            'message' => "no declara la clase {$base} que espera PSR-4 por su nombre",
        ]];
    }

    /**
     * Namespace, tipos declarados, clases referenciadas (ya resueltas) y rutas de require/include.
     *
     * @return array{namespace: string, types: list<string>, references: list<array{string, int}>, includes: list<array{string, int}>}
     */
    private static function parse(string $file): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file)),
            static fn(PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $name = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        $namespace = '';
        $uses = [];
        $types = [];
        $names = [];
        $includes = [];
        $depth = 0;

        foreach ($tokens as $i => $token) {
            $prev = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $depth += match ($token->text) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($token->is(T_NAMESPACE) && $next?->is([T_STRING, T_NAME_QUALIFIED]) === true) {
                $namespace = $next->text;
            } elseif ($token->is(T_USE) && $depth === 0 && $next?->is($name) === true) {
                // Una importación: la de un closure o la de un trait en una clase tienen otra profundidad
                $import = ltrim($next->text, '\\');
                $alias = ($tokens[$i + 2] ?? null)?->is(T_AS) === true
                    ? (string) ($tokens[$i + 3] ?? null)?->text
                    : substr((string) strrchr('\\' . $import, '\\'), 1);
                $uses[strtolower($alias)] = $import;
                $names[] = ['\\' . $import, $next->line];
            } elseif (
                $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])
                && $next?->is(T_STRING) === true
                && $prev?->is([T_DOUBLE_COLON, T_NEW]) !== true
            ) {
                $types[] = $next->text;
            } elseif (
                (
                    $token->is([T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF])
                    || $token->text === ','
                    && self::inImplements($tokens, $i)
                )
                && $next?->is($name) === true
            ) {
                $names[] = [$next->text, $next->line];
            } elseif ($token->is(T_DOUBLE_COLON) && $prev?->is($name) === true) {
                $names[] = [$prev->text, $prev->line];
            } elseif ($token->is([T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE])) {
                $path = self::literalPath($tokens, $i + 1, dirname($file));
                if ($path !== null) {
                    $includes[] = [$path, $token->line];
                }
            }
        }

        $references = [];
        foreach ($names as [$reference, $line]) {
            if (in_array(strtolower($reference), ['self', 'static', 'parent'], true)) {
                continue;
            }

            $references[] = [self::resolve($reference, $namespace, $uses), $line];
        }

        return ['namespace' => $namespace, 'types' => $types, 'references' => $references, 'includes' => $includes];
    }

    /**
     * ¿La coma separa interfaces de un `implements`? Las demás comas no nombran clases.
     *
     * @param list<PhpToken> $tokens
     */
    private static function inImplements(array $tokens, int $index): bool
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            if ($tokens[$j]->is(T_IMPLEMENTS)) {
                return true;
            }

            if ($tokens[$j]->text !== ',' && !$tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                return false;
            }
        }

        return false;
    }

    /**
     * `require 'x'` o `require __DIR__ . '/x'` (con o sin paréntesis); otra expresión no se puede revisar.
     *
     * @param list<PhpToken> $tokens
     */
    private static function literalPath(array $tokens, int $index, string $dir): ?string
    {
        $parts = [];
        for ($j = $index; isset($tokens[$j]) && $tokens[$j]->text !== ';'; $j++) {
            if ($tokens[$j]->is(T_DIR)) {
                $parts[] = $dir;
            } elseif ($tokens[$j]->is(T_CONSTANT_ENCAPSED_STRING)) {
                $parts[] = substr($tokens[$j]->text, 1, -1);
            } elseif (!in_array($tokens[$j]->text, ['.', '(', ')'], true)) {
                return null;
            }
        }

        $path = implode('', $parts);

        return match (true) {
            $path === '' => null,
            str_starts_with($path, '/') => $path,
            default => getcwd() . '/' . $path,
        };
    }

    /** @param array<string, string> $uses alias en minúsculas => clase */
    private static function resolve(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = explode('\\', $name)[0];
        $import = $uses[strtolower($first)] ?? null;

        return $import !== null ? $import . substr($name, strlen($first)) : ltrim($namespace . '\\' . $name, '\\');
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $dir,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    private static function relative(string $file, string $root): string
    {
        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }
}
