<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use Exception;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\cli\CLIErrorHandler;
use versaWYS\kernel\cli\CLIHelper;

class CheckPsr4Command extends BaseCommand
{
    private array $availableClasses = [];

    /** @var array<string, true> Todas las clases, interfaces, traits y enums declarados, en minúsculas */
    private array $declaredTypes = [];

    /** @var list<string> Prefijos PSR-4 del proyecto, en minúsculas (app\, versawys\kernel\) */
    private array $projectPrefixes = [];

    private array $classToFile = [];

    private array $classMethods = [];

    private array $classInheritance = [];

    /** @var array<string,array<mixed>> Cache de tokens por filepath para evitar re-tokenización */
    private array $tokenCache = [];

    #[Override]
    public function execute(array $params): int
    {
        $projectRoot = getcwd();
        $composerDir = CLIHelper::composerDir();
        $composerFile = $composerDir . DIRECTORY_SEPARATOR . 'composer.json';

        if (!file_exists($composerFile)) {
            CLIErrorHandler::error("No se encontró composer.json en: {$composerFile}");

            return 1;
        }

        $composer = json_decode(file_get_contents($composerFile), true);
        if (!isset($composer['autoload']['psr-4'])) {
            CLIErrorHandler::info('No se detectaron mapeos PSR-4 en composer.json');

            return 0;
        }

        if (in_array('--deadcode', $params, true)) {
            CLIErrorHandler::info(
                '--deadcode se eliminó en versawys/kernel 2.10: para métodos y variables sin uso, usar phpstan o mago.',
            );
        }

        CLIErrorHandler::printSeparator();
        echo CLIErrorHandler::colorize('★ Análisis Completo de Cumplimiento PSR-4', 'cyan') . "\n";
        echo CLIErrorHandler::colorize('  (Estructura, namespaces, case-sensitivity y referencias)', 'yellow') . "\n\n";

        $issues = [];
        $psr4Mappings = [];

        echo CLIErrorHandler::colorize('FASE 1: Construyendo índice de clases...', 'cyan') . "\n";

        foreach ($composer['autoload']['psr-4'] as $prefix => $path) {
            $absolutePath = realpath($composerDir . DIRECTORY_SEPARATOR . $path);

            if ($absolutePath === false || !is_dir($absolutePath)) {
                $issues[] = [
                    'type' => 'missing_dir',
                    'message' => "Mapping '{$prefix}' -> '{$path}' no existe",
                ];
                continue;
            }

            $dir = rtrim($absolutePath, DIRECTORY_SEPARATOR);
            $psr4Mappings[$prefix] = $dir;
            $this->projectPrefixes[] = strtolower($prefix);
            $relPath = str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $dir);
            echo "  • {$prefix} => {$relPath}\n";
            $this->buildClassIndex($dir, $prefix);
        }

        echo CLIErrorHandler::colorize("\n  ✓ Clases indexadas: " . count($this->availableClasses), 'green') . "\n\n";

        echo CLIErrorHandler::colorize('FASE 2: Verificando estructura PSR-4...', 'cyan') . "\n";

        foreach ($psr4Mappings as $prefix => $dir) {
            echo "  • Validando: {$prefix}\n";
            $dirIssues = $this->scanDirectory($dir, $prefix, $projectRoot);
            $issues = array_merge($issues, $dirIssues);
        }

        echo "\n";

        echo CLIErrorHandler::colorize('FASE 3: Verificando use statements...', 'cyan') . "\n";

        foreach ($psr4Mappings as $prefix => $dir) {
            echo "  • Analizando referencias en: {$prefix}\n";
            $useIssues = $this->scanUseStatements($dir, $projectRoot);
            $issues = array_merge($issues, $useIssues);
        }

        echo "\n";

        echo CLIErrorHandler::colorize('FASE 3.5: Verificando llamadas estáticas (Clase::)...', 'cyan') . "\n";

        foreach ($psr4Mappings as $prefix => $dir) {
            echo "  • Analizando llamadas estáticas en: {$prefix}\n";
            $staticIssues = $this->scanStaticClassReferences($dir, $projectRoot);
            $issues = array_merge($issues, $staticIssues);
        }

        echo "\n";

        echo CLIErrorHandler::colorize('FASE 3.6: Verificando instanciaciones (new Clase)...', 'cyan') . "\n";

        foreach ($psr4Mappings as $prefix => $dir) {
            echo "  • Analizando instanciaciones en: {$prefix}\n";
            $newIssues = $this->scanNewClassReferences($dir, $projectRoot);
            $issues = array_merge($issues, $newIssues);
        }

        echo "\n";

        echo CLIErrorHandler::colorize('FASE 4: Verificando rutas en require/include...', 'cyan') . "\n";

        foreach ($psr4Mappings as $prefix => $dir) {
            echo "  • Analizando rutas en: {$prefix}\n";
            $pathIssues = $this->scanFilePaths($dir, $projectRoot);
            $issues = array_merge($issues, $pathIssues);
        }

        echo "\n";

        echo CLIErrorHandler::colorize('FASE 5: Verificando existencia de métodos...', 'cyan') . "\n";

        foreach ($psr4Mappings as $prefix => $dir) {
            echo "  • Analizando llamadas a métodos en: {$prefix}\n";
            $methodIssues = $this->scanMethodReferences($dir, $projectRoot);
            $issues = array_merge($issues, $methodIssues);
        }

        echo "\n";

        CLIErrorHandler::printSeparator();

        if (count($issues) === 0) {
            CLIErrorHandler::success('✓ ¡Excelente! No se encontraron inconsistencias PSR-4');
            echo "\n  Todas las clases, namespaces y referencias están correctamente configuradas.\n";

            return 0;
        }

        $grouped = $this->groupIssues($issues);

        echo CLIErrorHandler::colorize('⚠ Se encontraron ' . count($issues) . ' inconsistencia(s):', 'red') . "\n\n";

        foreach ($grouped as $type => $typeIssues) {
            $this->printIssueCategory($type, $typeIssues);
        }

        return 1;
    }

    // =========================================================
    // DEAD CODE DETECTION
    // =========================================================

    #[Override]
    public function getDescription(): string
    {
        return 'Análisis completo PSR-4: estructura, namespaces, case-sensitivity y referencias';
    }

    #[Override]
    public function getHelp(): string
    {
        return <<<'HELP'
            Uso: php versaCLI app:check:psr4

            Realiza un análisis exhaustivo de cumplimiento PSR-4:

              ✓ Verifica que los namespaces coincidan con la estructura de directorios
              ✓ Valida case-sensitivity en rutas y namespaces (crítico para Linux)
              ✓ Analiza todos los 'use' statements buscando referencias incorrectas
              ✓ Detecta uso de clases con case incorrecto que funcionarían en Windows pero fallarían en Linux
              ✓ Valida llamadas estáticas del tipo Clase::método con case exacto
              ✓ Valida rutas en require/include y funciones filesystem (file_exists, fopen, etc.)
              ✓ Verifica que todas las rutas físicas usen el case correcto

            Este comando es esencial para proyectos desarrollados en Windows que se desplegarán en Linux/Unix.

            Tipos de problemas detectados:
              - Namespace declarado no coincide con la ruta del archivo
              - Carpetas con case incorrecto (ej: 'services' vs 'Services')
              - Referencias 'use' con mayúsculas/minúsculas incorrectas
              - Clases referenciadas que no existen con ese case exacto
              - Rutas en require/include con case incorrecto
              - Archivos referenciados que no existen

            Código de salida:
              0 - Sin problemas encontrados
              1 - Se encontraron inconsistencias

            Ejemplos:
              php versaCLI app:check:psr4
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

            $entries = @scandir($current === '' ? '/' : $current);
            if ($entries === false) {
                return [null, null];
            }

            if (!in_array($segment, $entries, true)) {
                $actual = null;
                foreach ($entries as $entry) {
                    if (strcasecmp($entry, $segment) !== 0) {
                        continue;
                    }

                    $actual = $entry;
                    break;
                }

                if ($actual === null) {
                    return [null, null];
                }

                $mismatch ??= ['used' => $segment, 'expected' => $actual];
                $segment = $actual;
            }

            $current .= '/' . $segment;
        }

        return [$current, $mismatch];
    }

    private function buildClassIndex(string $dir, string $prefix): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $filePath = $file->getRealPath();
                $declared = $this->parsePhpFileDetails($filePath);
                $this->indexDeclaredTypes($filePath, (string) $declared['namespace']);

                if ($declared['class'] === '') {
                    continue;
                }

                $fqcn = ($declared['namespace'] ? $declared['namespace'] . '\\' : '') . $declared['class'];
                $lowercaseFqcn = strtolower($fqcn);

                $this->availableClasses[$lowercaseFqcn] = $fqcn;
                $this->classToFile[$fqcn] = $filePath;
                $this->classMethods[$lowercaseFqcn] = $declared['methods'];
                $this->classInheritance[$lowercaseFqcn] = [
                    'extends' => $declared['extends'] ? strtolower($declared['extends']) : null,
                    'implements' => array_map('strtolower', $declared['implements']),
                    'traits' => array_map('strtolower', $declared['traits']),
                ];
            }
        } catch (Exception) {
        }
    }

    private function scanDirectory(string $dir, string $prefix, string $projectRoot): array
    {
        $issues = [];

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $filePath = $file->getRealPath();
                $relPath = substr($filePath, strlen($dir) + 1);
                $relPathNs = str_replace(DIRECTORY_SEPARATOR, '\\', substr($relPath, 0, -4));
                $expectedFQCN = rtrim($prefix, '\\') . ($relPathNs === '' ? '' : '\\' . $relPathNs);

                $declared = $this->parsePhpFileDetails($filePath);
                if ($declared['class'] === '') {
                    continue;
                }

                $declaredFQCN = ($declared['namespace'] ? $declared['namespace'] . '\\' : '') . $declared['class'];

                if ($declaredFQCN !== $expectedFQCN) {
                    $issues[] = [
                        'type' => 'fqcn_mismatch',
                        'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                        'declared' => $declaredFQCN,
                        'expected' => $expectedFQCN,
                    ];
                    continue;
                }

                $caseIssue = $this->verifyCaseSensitivity($filePath, $dir, $declaredFQCN, $prefix, $projectRoot);
                if ($caseIssue !== null) {
                    $issues[] = $caseIssue;
                }
            }
        } catch (Exception $exception) {
            $issues[] = [
                'type' => 'scan_error',
                'message' => "Error escaneando {$dir}: " . $exception->getMessage(),
            ];
        }

        return $issues;
    }

    private function scanUseStatements(string $dir, string $projectRoot): array
    {
        $issues = [];

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $filePath = $file->getRealPath();
                $useStatements = $this->extractUseStatements($filePath);

                foreach ($useStatements as $use) {
                    $fqcn = $use['fqcn'];
                    $line = $use['line'];
                    $lowercaseFqcn = strtolower($fqcn);

                    if (!isset($this->availableClasses[$lowercaseFqcn])) {
                        if ($this->isMissingProjectClass($fqcn)) {
                            $issues[] = [
                                'type' => 'class_not_found',
                                'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                                'line' => $line,
                                'used' => 'use ' . $fqcn,
                                'resolved' => $fqcn,
                            ];
                        }

                        continue;
                    }

                    $correctFqcn = $this->availableClasses[$lowercaseFqcn];

                    if ($fqcn !== $correctFqcn) {
                        $issues[] = [
                            'type' => 'use_case_mismatch',
                            'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                            'line' => $line,
                            'used' => $fqcn,
                            'correct' => $correctFqcn,
                            'definition' => str_replace(
                                $projectRoot . DIRECTORY_SEPARATOR,
                                '',
                                $this->classToFile[$correctFqcn],
                            ),
                        ];
                    }
                }
            }
        } catch (Exception $exception) {
            $issues[] = [
                'type' => 'scan_error',
                'message' => "Error analizando use statements en {$dir}: " . $exception->getMessage(),
            ];
        }

        return $issues;
    }

    private function extractUseStatements(string $file): array
    {
        $uses = [];
        $tokens = $this->getTokens($file);
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            ++$i;
            while (isset($tokens[$i]) && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                ++$i;
            }

            $fqcn = '';
            $line = $token[2];
            while (isset($tokens[$i])) {
                $t = $tokens[$i];

                if (is_array($t)) {
                    if (in_array(
                        $t[0],
                        [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED],
                        strict: true,
                    )) {
                        $fqcn .= $t[1];
                        ++$i;
                        continue;
                    }

                    if ($t[0] === T_WHITESPACE) {
                        ++$i;
                        continue;
                    }

                    if ($t[0] === T_AS) {
                        break;
                    }
                } else {
                    if ($t === ';' || $t === ',') {
                        break;
                    }
                }

                ++$i;
            }

            $fqcn = ltrim($fqcn, '\\');
            if ($fqcn !== '') {
                $uses[] = [
                    'fqcn' => $fqcn,
                    'line' => $line,
                ];
            }
        }

        return $uses;
    }

    private function scanFilePaths(string $dir, string $projectRoot): array
    {
        $issues = [];

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $filePath = $file->getRealPath();
                $pathReferences = $this->extractFilePaths($filePath);

                foreach ($pathReferences as $ref) {
                    $issue = $this->checkPathReference($ref, $filePath, $projectRoot);
                    if ($issue !== null) {
                        $issues[] = $issue;
                    }
                }
            }
        } catch (Exception $exception) {
            $issues[] = [
                'type' => 'scan_error',
                'message' => "Error analizando rutas en {$dir}: " . $exception->getMessage(),
            ];
        }

        return $issues;
    }

    /**
     * Una clase referenciada dentro de un namespace del proyecto que ningún archivo declara.
     * En ejecución es un error fatal "Class not found"; ninguna comparación de mayúsculas lo detecta.
     */
    /**
     * Registra todos los tipos que declara un archivo, no solo el principal: un enum o una clase
     * auxiliar en el mismo archivo existen aunque no sigan PSR-4.
     */
    private function indexDeclaredTypes(string $file, string $namespace): void
    {
        $tokens = $this->getTokens($file);
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], strict: true)) {
                continue;
            }

            // `new class` y `Foo::class` no son declaraciones
            $prev = $this->getPrevSignificantTokenIndex($tokens, $i);
            if (
                $prev !== null
                && is_array($tokens[$prev])
                && in_array($tokens[$prev][0], [T_NEW, T_DOUBLE_COLON], strict: true)
            ) {
                continue;
            }

            $next = $this->getNextSignificantTokenIndex($tokens, $i);
            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                $this->declaredTypes[strtolower(ltrim($namespace . '\\' . $tokens[$next][1], '\\'))] = true;
            }
        }
    }

    private function isMissingProjectClass(string $fqcn): bool
    {
        $lower = strtolower(ltrim($fqcn, '\\'));
        if (
            isset($this->availableClasses[$lower])
            || isset($this->declaredTypes[$lower])
            || in_array(basename(str_replace('\\', '/', $lower)), ['self', 'static', 'parent'], true)
        ) {
            return false;
        }

        // `use app\models;` importa un namespace, no una clase
        foreach (array_keys($this->declaredTypes) as $declared) {
            if (str_starts_with($declared, $lower . '\\')) {
                return false;
            }
        }

        foreach ($this->projectPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function scanStaticClassReferences(string $dir, string $projectRoot): array
    {
        $issues = [];

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $filePath = $file->getRealPath();
                $references = $this->extractStaticClassReferences($filePath);

                foreach ($references as $ref) {
                    $lowercaseFqcn = strtolower($ref['resolved_fqcn']);

                    if (!isset($this->availableClasses[$lowercaseFqcn])) {
                        if ($this->isMissingProjectClass($ref['resolved_fqcn'])) {
                            $issues[] = [
                                'type' => 'class_not_found',
                                'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                                'line' => $ref['line'],
                                'used' => $ref['original'] . '::',
                                'resolved' => $ref['resolved_fqcn'],
                            ];
                        }

                        continue;
                    }

                    $correctFqcn = $this->availableClasses[$lowercaseFqcn];
                    if ($ref['resolved_fqcn'] === $correctFqcn) {
                        continue;
                    }

                    $issues[] = [
                        'type' => 'static_case_mismatch',
                        'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                        'line' => $ref['line'],
                        'used' => $ref['original'],
                        'resolved' => $ref['resolved_fqcn'],
                        'correct' => $correctFqcn,
                        'definition' => str_replace(
                            $projectRoot . DIRECTORY_SEPARATOR,
                            '',
                            $this->classToFile[$correctFqcn],
                        ),
                    ];
                }
            }
        } catch (Exception $exception) {
            $issues[] = [
                'type' => 'scan_error',
                'message' => "Error analizando llamadas estáticas en {$dir}: " . $exception->getMessage(),
            ];
        }

        return $issues;
    }

    private function scanNewClassReferences(string $dir, string $projectRoot): array
    {
        $issues = [];

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $filePath = $file->getRealPath();
                $references = $this->extractNewClassReferences($filePath);

                foreach ($references as $ref) {
                    $lowercaseFqcn = strtolower($ref['resolved_fqcn']);

                    if (!isset($this->availableClasses[$lowercaseFqcn])) {
                        if ($this->isMissingProjectClass($ref['resolved_fqcn'])) {
                            $issues[] = [
                                'type' => 'class_not_found',
                                'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                                'line' => $ref['line'],
                                'used' => 'new ' . $ref['original'],
                                'resolved' => $ref['resolved_fqcn'],
                            ];
                        }

                        continue;
                    }

                    $correctFqcn = $this->availableClasses[$lowercaseFqcn];
                    if ($ref['resolved_fqcn'] === $correctFqcn) {
                        continue;
                    }

                    $issues[] = [
                        'type' => 'new_case_mismatch',
                        'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                        'line' => $ref['line'],
                        'used' => $ref['original'],
                        'resolved' => $ref['resolved_fqcn'],
                        'correct' => $correctFqcn,
                        'definition' => str_replace(
                            $projectRoot . DIRECTORY_SEPARATOR,
                            '',
                            $this->classToFile[$correctFqcn],
                        ),
                    ];
                }
            }
        } catch (Exception $exception) {
            $issues[] = [
                'type' => 'scan_error',
                'message' => "Error analizando instanciaciones new en {$dir}: " . $exception->getMessage(),
            ];
        }

        return $issues;
    }

    private function extractStaticClassReferences(string $file): array
    {
        $references = [];
        $tokens = $this->getTokens($file);

        $context = $this->extractNamespaceAndUseMap($tokens);
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i] ?? null;

            if (!is_array($token) || $token[0] !== T_DOUBLE_COLON) {
                continue;
            }

            $j = $i - 1;
            while ($j >= 0 && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                --$j;
            }

            $nameParts = [];
            $line = 0;
            while ($j >= 0) {
                $prev = $tokens[$j];

                if (is_array($prev)) {
                    if (in_array(
                        $prev[0],
                        [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED],
                        strict: true,
                    )) {
                        array_unshift($nameParts, $prev[1]);
                        if ($line === 0) {
                            $line = $prev[2] ?? 0;
                        }

                        --$j;
                        continue;
                    }

                    if ($prev[0] === T_WHITESPACE) {
                        --$j;
                        continue;
                    }
                }

                break;
            }

            if ($nameParts === []) {
                continue;
            }

            $originalName = implode('', $nameParts);
            $normalizedOriginal = ltrim($originalName, '\\');
            $lowerOriginal = strtolower($normalizedOriginal);

            if (in_array($lowerOriginal, ['self', 'static', 'parent'], strict: true)) {
                continue;
            }

            // Con su barra inicial, si la tiene: \Clase es un nombre global, no relativo al namespace
            $resolved = $this->resolveClassNameFromContext($originalName, $context['namespace'], $context['uses']);
            if ($resolved === null) {
                continue;
            }

            $references[] = [
                'original' => $originalName,
                'resolved_fqcn' => $resolved,
                'line' => $line,
            ];
        }

        return $references;
    }

    private function extractNewClassReferences(string $file): array
    {
        $references = [];
        $tokens = $this->getTokens($file);

        $context = $this->extractNamespaceAndUseMap($tokens);
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i] ?? null;

            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                ++$j;
            }

            if ($j >= $count) {
                continue;
            }

            // Ignorar clases anónimas: new class (...) { ... }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_CLASS) {
                continue;
            }

            $nameParts = [];
            $line = is_array($tokens[$j]) ? $tokens[$j][2] ?? 0 : 0;

            while ($j < $count) {
                $current = $tokens[$j];

                if (is_array($current)) {
                    if (in_array(
                        $current[0],
                        [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED],
                        true,
                    )) {
                        $nameParts[] = $current[1];
                        ++$j;
                        continue;
                    }

                    if ($current[0] === T_WHITESPACE) {
                        ++$j;
                        continue;
                    }
                }

                break;
            }

            if ($nameParts === []) {
                continue;
            }

            $originalName = implode('', $nameParts);
            $normalizedOriginal = ltrim($originalName, '\\');

            // Con su barra inicial, si la tiene: \Clase es un nombre global, no relativo al namespace
            $resolved = $this->resolveClassNameFromContext($originalName, $context['namespace'], $context['uses']);
            if ($resolved === null) {
                continue;
            }

            $references[] = [
                'original' => $originalName,
                'resolved_fqcn' => $resolved,
                'line' => $line,
            ];
        }

        return $references;
    }

    private function extractNamespaceAndUseMap(array $tokens): array
    {
        $namespace = '';
        $uses = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i] ?? null;
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                ++$i;
                $ns = '';
                while ($i < $count) {
                    $current = $tokens[$i];
                    if ($current === ';' || $current === '{') {
                        break;
                    }

                    if (
                        is_array($current)
                        && in_array($current[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED], strict: true)
                    ) {
                        $ns .= $current[1];
                    }

                    ++$i;
                }

                $namespace = trim($ns, '\\');
                continue;
            }

            if ($token[0] !== T_USE) {
                continue;
            }

            // Ignorar closures: function (...) use (...)
            $prevSignificant = null;
            for ($k = $i - 1; $k >= 0; --$k) {
                $back = $tokens[$k];
                if (is_array($back) && $back[0] === T_WHITESPACE) {
                    continue;
                }

                $prevSignificant = $back;
                break;
            }

            if (is_array($prevSignificant) && $prevSignificant[0] === T_FUNCTION) {
                continue;
            }

            ++$i;
            $fqcn = '';
            $alias = '';
            $inAlias = false;

            while ($i < $count) {
                $current = $tokens[$i];

                if ($current === ';' || $current === ',') {
                    if ($fqcn !== '') {
                        $fqcnNormalized = ltrim(trim($fqcn), '\\');
                        $segments = explode('\\', $fqcnNormalized);
                        $defaultAlias = end($segments) ?: '';
                        $finalAlias = $alias !== '' ? $alias : $defaultAlias;
                        if ($finalAlias !== '') {
                            $uses[$finalAlias] = $fqcnNormalized;
                        }
                    }

                    if ($current === ';') {
                        break;
                    }

                    $fqcn = '';
                    $alias = '';
                    $inAlias = false;
                    ++$i;
                    continue;
                }

                if (is_array($current)) {
                    if ($current[0] === T_AS) {
                        $inAlias = true;
                        ++$i;
                        continue;
                    }

                    if (in_array(
                        $current[0],
                        [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED],
                        strict: true,
                    )) {
                        if ($inAlias) {
                            $alias .= $current[1];
                        } else {
                            $fqcn .= $current[1];
                        }
                    }
                }

                ++$i;
            }
        }

        return [
            'namespace' => $namespace,
            'uses' => $uses,
        ];
    }

    private function resolveClassNameFromContext(string $name, string $namespace, array $uses): ?string
    {
        if ($name === '') {
            return null;
        }

        // Nombre completamente calificado
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        // PHP compara los alias de `use` sin distinguir mayúsculas
        $aliases = array_change_key_case($uses, CASE_LOWER);

        // Resolver alias de use (primera parte del nombre)
        if (str_contains($name, '\\')) {
            [$firstSegment, $rest] = explode('\\', $name, 2);
            if (isset($aliases[strtolower($firstSegment)])) {
                return $aliases[strtolower($firstSegment)] . '\\' . $rest;
            }

            // Nombre calificado relativo al namespace actual
            return $namespace !== '' ? $namespace . '\\' . $name : $name;
        }

        return $aliases[strtolower($name)] ?? ($namespace !== '' ? $namespace . '\\' . $name : $name);
    }

    private function extractFilePaths(string $file): array
    {
        $paths = [];
        $tokens = $this->getTokens($file);
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            $type = null;
            $line = $token[2] ?? 0;

            if (in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], strict: true)) {
                $type = match ($token[0]) {
                    T_REQUIRE => 'require',
                    T_REQUIRE_ONCE => 'require_once',
                    T_INCLUDE => 'include',
                    T_INCLUDE_ONCE => 'include_once',
                };
            }

            if ($token[0] === T_STRING) {
                $funcName = strtolower($token[1]);
                if (in_array(
                    $funcName,
                    [
                        'file_exists',
                        'is_file',
                        'is_dir',
                        'is_readable',
                        'is_writable',
                        'fopen',
                        'file_get_contents',
                        'file_put_contents',
                        'readfile',
                        'unlink',
                        'copy',
                        'rename',
                        'mkdir',
                        'rmdir',
                        'scandir',
                        'glob',
                        'realpath',
                        'file',
                        'touch',
                        'chmod',
                    ],
                    strict: true,
                )) {
                    $type = $funcName;
                }
            }

            if ($type === null) {
                continue;
            }

            $path = $this->extractPathString($tokens, $i, $count);
            if ($path !== null) {
                $paths[] = $path + ['line' => $line, 'type' => $type];
            }
        }

        return $paths;
    }

    /**
     * Ruta literal que sigue a require/include o a una función de archivos: `'ruta'` o `__DIR__ . '/ruta'`,
     * con o sin paréntesis. Cualquier otra expresión (variables, concatenaciones más largas) es dinámica
     * y no se puede comprobar: devuelve null.
     *
     * @return array{path: string, from_dir: bool}|null
     */
    private function extractPathString(array $tokens, int $startIndex, int $count): ?array
    {
        $i = $this->getNextSignificantTokenIndex($tokens, $startIndex);
        if ($i !== null && $tokens[$i] === '(') {
            $i = $this->getNextSignificantTokenIndex($tokens, $i);
        }

        $fromDir = false;
        if ($i !== null && is_array($tokens[$i]) && $tokens[$i][0] === T_DIR) {
            $dot = $this->getNextSignificantTokenIndex($tokens, $i);
            if ($dot === null || $tokens[$dot] !== '.') {
                return null;
            }

            $fromDir = true;
            $i = $this->getNextSignificantTokenIndex($tokens, $dot);
        }

        if ($i === null || !is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $end = $this->getNextSignificantTokenIndex($tokens, $i);
        if ($end !== null && !in_array($tokens[$end], [')', ';', ','], true)) {
            return null;
        }

        return ['path' => trim($tokens[$i][1], '\'"'), 'from_dir' => $fromDir];
    }

    /**
     * Comprueba una ruta literal contra el disco distinguiendo mayúsculas, como lo hará Linux.
     *
     * @param array{path: string, line: int, type: string, from_dir: bool} $ref
     *
     * @return array<string, mixed>|null
     */
    private function checkPathReference(array $ref, string $sourceFile, string $projectRoot): ?array
    {
        $path = $ref['path'];
        if ($path === '' || preg_match('/[$\{*?]|:\/\//', $path) === 1) {
            return null;
        }

        $isAbsolute = $path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $bases = match (true) {
            $ref['from_dir'] => [dirname($sourceFile)],
            $isAbsolute => [''],
            // Una ruta relativa se resuelve desde la raíz del proyecto (index.php y versaCLI) y,
            // en require/include, también desde la carpeta del propio archivo
            default => [$projectRoot, dirname($sourceFile)],
        };

        $mismatch = null;
        foreach ($bases as $base) {
            [$found, $caseDiff] = self::locatePath($base === '' ? $path : $base . '/' . ltrim($path, '/'));
            if ($found === null) {
                continue;
            }

            if ($caseDiff === null) {
                return null; // existe con la capitalización exacta
            }

            $mismatch ??= $caseDiff;
        }

        $common = [
            'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $sourceFile),
            'line' => $ref['line'],
            'path' => $path,
            'context' => $ref['type'],
        ];

        if ($mismatch !== null) {
            return $common
            + [
                'type' => 'path_case_mismatch',
                'expected_case' => $mismatch['expected'],
                'used_case' => $mismatch['used'],
            ];
        }

        // Que el archivo no exista solo es un error cuando se intenta cargar; file_exists, mkdir o
        // file_put_contents trabajan precisamente con rutas que pueden no existir
        return in_array($ref['type'], ['require', 'require_once', 'include', 'include_once'], true)
            ? $common + ['type' => 'path_not_found']
            : null;
    }

    private function verifyCaseSensitivity(
        string $filePath,
        string $baseDir,
        string $declaredFQCN,
        string $prefix,
        string $projectRoot,
    ): ?array {
        $nsSegments = explode('\\', ltrim($declaredFQCN, '\\'));
        $className = array_pop($nsSegments);

        $prefixTrim = trim($prefix, '\\');
        if ($prefixTrim !== '') {
            $prefixSegs = explode('\\', $prefixTrim);
            $match = true;
            foreach ($prefixSegs as $k => $pseg) {
                if (isset($nsSegments[$k]) && strcasecmp($nsSegments[$k], $pseg) === 0) {
                    continue;
                }

                $match = false;
                break;
            }

            if ($match) {
                $nsSegments = array_slice($nsSegments, count($prefixSegs));
            }
        }

        $parts = $nsSegments;
        $parts[] = $className . '.php';

        $current = rtrim($baseDir, DIRECTORY_SEPARATOR);
        foreach ($parts as $part) {
            if (!is_dir($current) && !file_exists($current)) {
                break;
            }

            $entries = is_dir($current) ? scandir($current) : [];
            $matched = null;

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                if (strcasecmp($entry, $part) === 0) {
                    if ($entry !== $part) {
                        return [
                            'type' => 'case_mismatch',
                            'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                            'expected_case' => $part,
                            'actual_case' => $entry,
                            'context' => 'Estructura de directorios no coincide con namespace',
                        ];
                    }

                    $matched = $entry;
                    break;
                }
            }

            if ($matched === null) {
                return null;
            }

            $current .= DIRECTORY_SEPARATOR . $matched;
        }

        return null;
    }

    private function groupIssues(array $issues): array
    {
        $grouped = [];
        foreach ($issues as $issue) {
            $type = $issue['type'] ?? 'unknown';
            $grouped[$type] ??= [];

            $grouped[$type][] = $issue;
        }

        return $grouped;
    }

    private function printIssueCategory(string $type, array $issues): void
    {
        $count = count($issues);

        $titles = [
            'fqcn_mismatch' => "Namespace no coincide con ruta ({$count})",
            'case_mismatch' => "Problema de mayúsculas/minúsculas en rutas ({$count})",
            'use_case_mismatch' => "Problema de mayúsculas/minúsculas en 'use' statements ({$count})",
            'static_case_mismatch' => "Problema de mayúsculas/minúsculas en llamadas estáticas Clase:: ({$count})",
            'new_case_mismatch' => "Problema de mayúsculas/minúsculas en instanciaciones new Clase ({$count})",
            'class_not_found' => "Clase del proyecto que no existe ({$count})",
            'path_not_found' => "Rutas no encontradas en require/include ({$count})",
            'path_case_mismatch' => "Problema de mayúsculas/minúsculas en rutas de archivos ({$count})",
            'method_not_found' => "Método no encontrado ({$count})",
            'method_case_mismatch' => "Problema de mayúsculas/minúsculas en llamadas a métodos ({$count})",
            'missing_dir' => "Directorio no encontrado ({$count})",
            'scan_error' => "Errores de escaneo ({$count})",
        ];

        $title = $titles[$type] ?? "Otros problemas ({$count})";

        echo CLIErrorHandler::colorize("▸ {$title}", 'yellow') . "\n\n";

        foreach ($issues as $issue) {
            $this->printIssue($issue);
        }

        echo "\n";
    }

    private function printIssue(array $issue): void
    {
        switch ($issue['type']) {
            case 'fqcn_mismatch':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}\n";
                echo '    Declarado: ' . CLIErrorHandler::colorize($issue['declared'], 'cyan') . "\n";
                echo '    Esperado:  ' . CLIErrorHandler::colorize($issue['expected'], 'green') . "\n";
                break;

            case 'case_mismatch':
                echo ' ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}\n";
                echo '    Esperado: ' . CLIErrorHandler::colorize($issue['expected_case'], 'green') . "\n";
                echo '    Actual:   ' . CLIErrorHandler::colorize($issue['actual_case'], 'cyan') . "\n";
                if (isset($issue['context'])) {
                    echo '    ' . CLIErrorHandler::colorize($issue['context'], 'yellow') . "\n";
                }

                break;

            case 'use_case_mismatch':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}:{$issue['line']}\n";
                echo '    Usado:     ' . CLIErrorHandler::colorize($issue['used'], 'cyan') . "\n";
                echo '    Correcto:  ' . CLIErrorHandler::colorize($issue['correct'], 'green') . "\n";
                echo "    Definido en: {$issue['definition']}\n";
                break;

            case 'static_case_mismatch':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}:{$issue['line']}\n";
                echo '    Llamado:   ' . CLIErrorHandler::colorize($issue['used'] . '::', 'cyan') . "\n";
                echo '    Resuelto:  ' . CLIErrorHandler::colorize($issue['resolved'], 'yellow') . "\n";
                echo '    Correcto:  ' . CLIErrorHandler::colorize($issue['correct'], 'green') . "\n";
                echo "    Definido en: {$issue['definition']}\n";
                break;

            case 'new_case_mismatch':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}:{$issue['line']}\n";
                echo '    Instanciado: ' . CLIErrorHandler::colorize('new ' . $issue['used'], 'cyan') . "\n";
                echo '    Resuelto:    ' . CLIErrorHandler::colorize($issue['resolved'], 'yellow') . "\n";
                echo '    Correcto:    ' . CLIErrorHandler::colorize($issue['correct'], 'green') . "\n";
                echo "    Definido en: {$issue['definition']}\n";
                break;

            case 'class_not_found':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}:{$issue['line']}\n";
                echo '    Referencia: ' . CLIErrorHandler::colorize($issue['used'], 'cyan') . "\n";
                echo '    Resuelto:   ' . CLIErrorHandler::colorize($issue['resolved'], 'yellow') . "\n";
                echo '    ' . CLIErrorHandler::colorize('Ningún archivo del proyecto declara esta clase', 'red') . "\n";
                break;

            case 'static_call_on_non_static':
                echo '   ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}:{$issue['line']}\n";
                echo "    Clase:     {$issue['class']}\n";
                echo '    Método:     ' . CLIErrorHandler::colorize($issue['method'], 'cyan') . "\n";
                echo
                    '     '
                        . CLIErrorHandler::colorize('El método no es estático pero se llama usando ::', 'red')
                        . "\n\n";
                break;

            case 'path_not_found':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}:{$issue['line']}\n";
                echo "    Función:   {$issue['context']}\n";
                echo '    Ruta:      ' . CLIErrorHandler::colorize($issue['path'], 'cyan') . "\n";
                echo '    ' . CLIErrorHandler::colorize('Archivo no encontrado', 'red') . "\n";
                break;

            case 'path_case_mismatch':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}:{$issue['line']}\n";
                echo "    Función:   {$issue['context']}\n";
                echo "    Ruta:      {$issue['path']}\n";
                echo '    Usado:     ' . CLIErrorHandler::colorize($issue['used_case'], 'cyan') . "\n";
                echo '    Correcto:  ' . CLIErrorHandler::colorize($issue['expected_case'], 'green') . "\n";
                break;

            case 'method_not_found':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}:{$issue['line']}\n";
                echo "    Clase:     {$issue['class']}\n";
                echo '    Método:    ' . CLIErrorHandler::colorize($issue['method'], 'cyan') . "\n";
                echo '    ' . CLIErrorHandler::colorize('Método no encontrado', 'red') . "\n";
                break;

            case 'method_case_mismatch':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['file']}:{$issue['line']}\n";
                echo "    Clase:     {$issue['class']}\n";
                echo '    Usado:     ' . CLIErrorHandler::colorize($issue['used'], 'cyan') . "\n";
                echo '    Correcto:  ' . CLIErrorHandler::colorize($issue['correct'], 'green') . "\n";
                break;

            case 'missing_dir':
            case 'scan_error':
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . " {$issue['message']}\n";
                break;

            default:
                echo '  ' . CLIErrorHandler::colorize('✗', 'red') . ' Error desconocido: ' . json_encode($issue) . "\n";
        }
    }

    private function scanMethodReferences(string $dir, string $projectRoot): array
    {
        $issues = [];

        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ));

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $filePath = $file->getRealPath();
                $details = $this->parsePhpFileDetails($filePath);
                $currentClass = $details['class'] !== ''
                    ? ($details['namespace'] ? $details['namespace'] . '\\' : '') . $details['class']
                    : null;

                $refs = $this->extractMethodReferences($filePath, $currentClass);

                foreach ($refs as $ref) {
                    $class = $ref['class'];
                    $method = $ref['method'];
                    $line = $ref['line'];
                    $lowercaseClass = strtolower($class);

                    if (!isset($this->availableClasses[$lowercaseClass])) {
                        continue;
                    }

                    $result = $this->checkMethodExists($lowercaseClass, $method, $ref['static']);

                    if ($result === 'not_found') {
                        $issues[] = [
                            'type' => 'method_not_found',
                            'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                            'line' => $line,
                            'class' => $class,
                            'method' => $method,
                        ];
                    } elseif ($result === 'static_error') {
                        $issues[] = [
                            'type' => 'static_call_on_non_static',
                            'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                            'line' => $line,
                            'class' => $class,
                            'method' => $method,
                        ];
                    } elseif (is_string($result)) {
                        $issues[] = [
                            'type' => 'method_case_mismatch',
                            'file' => str_replace($projectRoot . DIRECTORY_SEPARATOR, '', $filePath),
                            'line' => $line,
                            'class' => $class,
                            'used' => $method,
                            'correct' => $result,
                        ];
                    }
                }
            }
        } catch (Exception $exception) {
            $issues[] = [
                'type' => 'scan_error',
                'message' => "Error analizando métodos en {$dir}: " . $exception->getMessage(),
            ];
        }

        return $issues;
    }

    private function checkMethodExists(
        string $lowercaseClass,
        string $methodName,
        bool $mustBeStatic = false,
    ): bool|string {
        $lowerMethod = strtolower($methodName);

        if (isset($this->classMethods[$lowercaseClass])) {
            foreach ($this->classMethods[$lowercaseClass] as $m) {
                if (strtolower($m['name']) !== $lowerMethod) {
                    continue;
                }

                if ($mustBeStatic && !$m['static']) {
                    return 'static_error';
                }

                return $m['name'] === $methodName ? true : $m['name'];
            }
        }

        if (isset($this->classInheritance[$lowercaseClass])) {
            $inh = $this->classInheritance[$lowercaseClass];

            if ($inh['extends']) {
                $parent = $inh['extends'];
                if (!isset($this->availableClasses[$parent])) {
                    // Si el padre no está indexado (ej: vendor), asumimos que el método podría existir allí
                    return true;
                }

                $res = $this->checkMethodExists($parent, $methodName, $mustBeStatic);
                if ($res !== 'not_found' && $res !== false) {
                    return $res;
                }
            }

            foreach (array_merge($inh['implements'], $inh['traits']) as $ref) {
                if (!isset($this->availableClasses[$ref])) {
                    // Si la interfaz o trait no está indexado, asumimos que el método podría estar allí
                    return true;
                }

                $res = $this->checkMethodExists($ref, $methodName, $mustBeStatic);
                if ($res !== 'not_found' && $res !== false) {
                    return $res;
                }
            }
        }

        return 'not_found';
    }

    private function extractMethodReferences(string $file, ?string $currentClass): array
    {
        $refs = [];
        $tokens = $this->getTokens($file);
        $count = count($tokens);
        $context = $this->extractNamespaceAndUseMap($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$this') {
                $next = $this->getNextSignificantTokenIndex($tokens, $i);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_OBJECT_OPERATOR) {
                    $next2 = $this->getNextSignificantTokenIndex($tokens, $next);
                    if ($next2 !== null && is_array($tokens[$next2]) && $tokens[$next2][0] === T_STRING) {
                        $next3 = $this->getNextSignificantTokenIndex($tokens, $next2);
                        if ($next3 !== null && $tokens[$next3] === '(') {
                            if ($currentClass) {
                                $refs[] = [
                                    'class' => $currentClass,
                                    'method' => $tokens[$next2][1],
                                    'line' => $tokens[$next2][2],
                                    'static' => false,
                                ];
                            }
                        }
                    }
                }
            }

            if (is_array($token) && $token[0] === T_DOUBLE_COLON) {
                $prevIdx = $this->getPrevSignificantTokenIndex($tokens, $i);
                if ($prevIdx !== null) {
                    $prev = $tokens[$prevIdx];
                    $className = '';

                    if (
                        is_array($prev)
                        && in_array($prev[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], strict: true)
                    ) {
                        $className = $prev[1];
                    }

                    if ($className !== '') {
                        $resolvedClass = null;
                        if (in_array(strtolower($className), ['self', 'static'], strict: true)) {
                            $resolvedClass = $currentClass;
                        } else {
                            $resolvedClass = $this->resolveClassNameFromContext(
                                $className,
                                $context['namespace'],
                                $context['uses'],
                            );
                        }

                        if ($resolvedClass) {
                            $next = $this->getNextSignificantTokenIndex($tokens, $i);
                            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                                $next2 = $this->getNextSignificantTokenIndex($tokens, $next);
                                if ($next2 !== null && $tokens[$next2] === '(') {
                                    $refs[] = [
                                        'class' => $resolvedClass,
                                        'method' => $tokens[$next][1],
                                        'line' => $tokens[$next][2],
                                        'static' => true,
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            if ($token === '[' || is_array($token) && $token[0] === T_ARRAY) {
                $idx = $this->getNextSignificantTokenIndex($tokens, $i);
                if (
                    $idx !== null
                    && is_array($tokens[$idx])
                    && in_array($tokens[$idx][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], strict: true)
                ) {
                    $className = $tokens[$idx][1];
                    $idx2 = $this->getNextSignificantTokenIndex($tokens, $idx);
                    if ($idx2 !== null && is_array($tokens[$idx2]) && $tokens[$idx2][0] === T_DOUBLE_COLON) {
                        $idx3 = $this->getNextSignificantTokenIndex($tokens, $idx2);
                        if ($idx3 !== null && is_array($tokens[$idx3]) && $tokens[$idx3][0] === T_CLASS) {
                            $idx4 = $this->getNextSignificantTokenIndex($tokens, $idx3);
                            if ($idx4 !== null && $tokens[$idx4] === ',') {
                                $idx5 = $this->getNextSignificantTokenIndex($tokens, $idx4);
                                if (
                                    $idx5 !== null
                                    && is_array($tokens[$idx5])
                                    && $tokens[$idx5][0] === T_CONSTANT_ENCAPSED_STRING
                                ) {
                                    $method = trim($tokens[$idx5][1], "'\"");
                                    $resolvedClass = $this->resolveClassNameFromContext(
                                        $className,
                                        $context['namespace'],
                                        $context['uses'],
                                    );
                                    if ($resolvedClass) {
                                        $refs[] = [
                                            'class' => $resolvedClass,
                                            'method' => $method,
                                            'line' => $tokens[$idx5][2],
                                            'static' => false,
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $refs;
    }

    /**
     * Retorna los tokens PHP de un archivo, usando cache para evitar re-tokenización.
     *
     * @return array<mixed>
     */
    private function getTokens(string $filePath): array
    {
        $this->tokenCache[$filePath] ??= token_get_all(file_get_contents($filePath));

        return $this->tokenCache[$filePath];
    }

    private function getNextSignificantTokenIndex(array $tokens, int $index): ?int
    {
        $i = $index + 1;
        while (isset($tokens[$i])) {
            if (
                is_array($tokens[$i])
                && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], strict: true)
            ) {
                ++$i;
                continue;
            }

            return $i;
        }

        return null;
    }

    private function getPrevSignificantTokenIndex(array $tokens, int $index): ?int
    {
        $i = $index - 1;
        while ($i >= 0) {
            if (
                is_array($tokens[$i])
                && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], strict: true)
            ) {
                --$i;
                continue;
            }

            return $i;
        }

        return null;
    }

    private function parsePhpFileDetails(string $file): array
    {
        $tokens = $this->getTokens($file);
        $count = count($tokens);

        $namespace = '';
        $class = '';
        $extends = null;
        $implements = [];
        $traits = [];
        $methods = [];

        // Extraer contexto una sola vez para eficiencia
        $context = $this->extractNamespaceAndUseMap($tokens);
        $namespace = $context['namespace'];

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], strict: true)) {
                $prevIdx = $this->getPrevSignificantTokenIndex($tokens, $i);
                if ($prevIdx !== null && is_array($tokens[$prevIdx]) && $tokens[$prevIdx][0] === T_NEW) {
                    continue;
                }

                $i = $this->getNextSignificantTokenIndex($tokens, $i);
                if (isset($tokens[$i]) && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                    $class = $tokens[$i][1];

                    $i++;
                    while ($i < $count && $tokens[$i] !== '{') {
                        if (is_array($tokens[$i]) && $tokens[$i][0] === T_EXTENDS) {
                            $i = $this->getNextSignificantTokenIndex($tokens, $i);
                            $ext = '';
                            while (
                                isset($tokens[$i])
                                && is_array($tokens[$i])
                                && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], strict: true)
                            ) {
                                $ext .= $tokens[$i][1];
                                ++$i;
                            }

                            $extends = $this->resolveClassNameFromContext($ext, $namespace, $context['uses']);
                            continue;
                        }

                        if (is_array($tokens[$i]) && $tokens[$i][0] === T_IMPLEMENTS) {
                            $i = $this->getNextSignificantTokenIndex($tokens, $i);
                            while (
                                isset($tokens[$i])
                                && $tokens[$i] !== '{'
                                && (is_string($tokens[$i]) || $tokens[$i][0] !== T_EXTENDS)
                            ) {
                                $impl = '';
                                while (
                                    isset($tokens[$i])
                                    && is_array($tokens[$i])
                                    && in_array(
                                        $tokens[$i][0],
                                        [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR],
                                        strict: true,
                                    )
                                ) {
                                    $impl .= $tokens[$i][1];
                                    $i = $this->getNextSignificantTokenIndex($tokens, $i);
                                }

                                if ($impl !== '') {
                                    $implements[] = $this->resolveClassNameFromContext(
                                        $impl,
                                        $namespace,
                                        $context['uses'],
                                    );
                                }

                                if (isset($tokens[$i]) && $tokens[$i] === ',') {
                                    $i = $this->getNextSignificantTokenIndex($tokens, $i);
                                } else {
                                    break;
                                }
                            }

                            continue;
                        }

                        $i++;
                    }

                    if (isset($tokens[$i]) && $tokens[$i] === '{') {
                        $depth = 1;
                        $i++;
                        while ($i < $count && $depth > 0) {
                            $t = $tokens[$i];

                            if (
                                $t === '{'
                                || is_array($t)
                                && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], strict: true)
                            ) {
                                ++$depth;
                            } elseif ($t === '}') {
                                --$depth;
                            } elseif ($depth === 1 && is_array($t)) {
                                if ($t[0] === T_USE) {
                                    $i = $this->getNextSignificantTokenIndex($tokens, $i);
                                    while (isset($tokens[$i]) && $tokens[$i] !== ';' && $tokens[$i] !== '{') {
                                        if (
                                            is_array($tokens[$i])
                                            && in_array(
                                                $tokens[$i][0],
                                                [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR],
                                                strict: true,
                                            )
                                        ) {
                                            $trName = $tokens[$i][1];
                                            $traits[] = $this->resolveClassNameFromContext(
                                                $trName,
                                                $namespace,
                                                $context['uses'],
                                            );
                                        }

                                        $i++;
                                    }
                                } elseif ($t[0] === T_FUNCTION) {
                                    $isStatic = false;
                                    $isAbstract = false;
                                    $isPrivate = false;
                                    $prevIdx = $this->getPrevSignificantTokenIndex($tokens, $i);
                                    // Walk backwards through modifiers (static, abstract, private, protected, public)
                                    $checkIdx = $prevIdx;
                                    while ($checkIdx !== null && is_array($tokens[$checkIdx])) {
                                        $mod = $tokens[$checkIdx][0];
                                        if ($mod === T_STATIC) {
                                            $isStatic = true;
                                        }

                                        if ($mod === T_ABSTRACT) {
                                            $isAbstract = true;
                                        }

                                        if ($mod === T_PRIVATE) {
                                            $isPrivate = true;
                                        }

                                        if (in_array(
                                            $mod,
                                            [
                                                T_PUBLIC,
                                                T_PROTECTED,
                                                T_PRIVATE,
                                                T_STATIC,
                                                T_ABSTRACT,
                                                T_FINAL,
                                                T_READONLY,
                                            ],
                                            strict: true,
                                        )) {
                                            $checkIdx = $this->getPrevSignificantTokenIndex($tokens, $checkIdx);
                                        } else {
                                            break;
                                        }
                                    }

                                    $i = $this->getNextSignificantTokenIndex($tokens, $i);
                                    if (isset($tokens[$i]) && is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                                        $methods[] = [
                                            'name' => $tokens[$i][1],
                                            'static' => $isStatic,
                                            'abstract' => $isAbstract,
                                            'private' => $isPrivate,
                                            'line' => $tokens[$i][2] ?? 0,
                                        ];
                                    }
                                }
                            }

                            $i++;
                        }
                    }

                    break;
                }
            }
        }

        return [
            'namespace' => $namespace,
            'class' => $class,
            'extends' => $extends,
            'implements' => $implements,
            'traits' => $traits,
            'methods' => $methods,
        ];
    }
}
