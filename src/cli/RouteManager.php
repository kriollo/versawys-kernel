<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

class RouteManager
{
    private static string $path = 'app/routes/';

    public static function createRoute(string $routeNameModule): void
    {
        $controllerName = ucfirst($routeNameModule) . 'Controller';
        $routeName = ucfirst($routeNameModule) . 'Routes';
        $routeNameModule = strtolower($routeNameModule);
        $middlewareName = ucfirst($routeNameModule) . 'Middleware';

        CLIErrorHandler::info("Creando ruta {$routeName}...");
        $routesFile = self::$path . ($routeName . '.php');

        if (file_exists($routesFile)) {
            if (!CLIHelper::confirm("La ruta {$routesFile} ya existe. Desea sobreescribirlo?")) {
                CLIErrorHandler::info('Operación cancelada.');
                exit();
            }

            unlink($routesFile);
        }

        $template = <<<'EOT'
            <?php

            declare(strict_types=1);

            namespace app\routes;

            use versaWYS\kernel\Router;
            use app\controllers\$controllerName;
            use app\middleware\AuthMiddleware;
            // use app\middleware\$middlewareName;

            // Rutas de Navegador
            // index => funcion que se ejecutara en el controlador
            Router::get('/admin/$routeNameModule',
                [$controllerName::class,'index']
            )->middleware([
                [AuthMiddleware::class, 'checkSession'],
                [AuthMiddleware::class, 'onlyUserWithAccess'],
                // [$middlewareName::class, 'middlewareMethod'],
            ]);

            EOT;

        $template = str_replace('$routeNameModule', $routeNameModule, $template);
        $template = str_replace('$routeName', $routeName, $template);
        $template = str_replace('$controllerName', $controllerName, $template);
        $template = str_replace('$middlewareName', $middlewareName, $template);

        CLIHelper::ensureDirectory(self::$path);

        if (file_put_contents($routesFile, $template)) {
            chmod($routesFile, 0o775);
            CLIErrorHandler::success("Ruta {$routesFile} creada.");
        } else {
            CLIErrorHandler::warning("No se pudo crear el archivo: {$routesFile}");
        }
    }

    public static function deleteRoute(string $routeName): void
    {
        $routeName = ucfirst($routeName) . 'Routes';

        CLIErrorHandler::info("Eliminando ruta {$routeName}...");
        $routesFile = self::$path . ($routeName . '.php');

        if (!file_exists($routesFile)) {
            CLIErrorHandler::warning("La ruta {$routesFile} no existe.");
            exit();
        }

        if (unlink($routesFile)) {
            CLIErrorHandler::success("Ruta {$routesFile} eliminada.");
        } else {
            CLIErrorHandler::warning("No se pudo eliminar: {$routesFile}");
        }
    }

    public static function getRoutes(): array
    {
        if (!is_dir(self::$path)) {
            return [];
        }
        $routes = scandir(self::$path);
        $routes = array_filter($routes, static fn ($route): bool => str_ends_with($route, '.php'));
        return array_values($routes);
    }

    public static function listRoutes(): void
    {
        $routes = self::getRoutes();

        CLIErrorHandler::info('Rutas:');
        $n = 1;
        foreach ($routes as $route) {
            echo sprintf('%d - %s%s', $n, $route, PHP_EOL);
            $n++;
        }

        echo "\n";
        $line = CLIHelper::ask('Seleccione una ruta');

        if (!is_numeric($line)) {
            CLIErrorHandler::info('Saliendo...');
            exit();
        }

        $line = (int) $line;
        if ($line < 1 || $line > count($routes)) {
            CLIErrorHandler::info('Saliendo...');
            exit();
        }

        $route = $routes[$line - 1];
        self::listarRutasFromRoute($route);
    }

    /**
     * Extrae datos de rutas de un archivo, incluyendo soporte para grupos anidados.
     *
     * @param string $routeFile Nombre del archivo de rutas
     *
     * @return array Datos de las rutas
     */
    public static function extractRouteData(string $routeFile): array
    {
        // El nombre llega desde la URL de /doc: solo se aceptan archivos que existen en app/routes
        if (!in_array($routeFile, self::getRoutes(), strict: true)) {
            throw new \InvalidArgumentException("El archivo de rutas '{$routeFile}' no existe.");
        }

        $routeContent = file_get_contents(self::$path . $routeFile);
        if ($routeContent === false) {
            throw new \RuntimeException("No se pudo leer el archivo de rutas: {$routeFile}");
        }

        return self::parseRouteSource($routeContent);
    }

    /**
     * Rutas de todos los archivos de app/routes, indexadas por archivo.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function extractAllRouteData(): array
    {
        $all = [];
        foreach (self::getRoutes() as $routeFile) {
            $all[$routeFile] = self::extractRouteData($routeFile);
        }

        return $all;
    }

    /**
     * Analiza el código de un archivo de rutas sin ejecutarlo.
     *
     * Cada ruta incluye, además de lo declarado: `url` (dirección real, con el prefijo del panel
     * configurado), `csrf` (none | protected | exempt) e `issues` (problemas detectados).
     *
     * @param string|null $adminUrl prefijo del panel; por defecto `admin.custom_url` de la configuración
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseRouteSource(string $source, ?string $adminUrl = null): array
    {
        $tokens = token_get_all($source);
        $routes = self::extractRoutesFromTokens($tokens, 0, count($tokens), [], []);
        $imports = self::parseImports($source);
        $adminUrl ??= (string) (ConfigManager::getConfig()['admin']['custom_url'] ?? 'admin');

        $previousGroupKey = null;
        foreach ($routes as &$route) {
            $route['groupKey'] = implode(' > ', array_column($route['groups'], 'path'));
            $route['showGroupHeading'] = $route['groupKey'] !== '' && $route['groupKey'] !== $previousGroupKey;
            $previousGroupKey = $route['groupKey'];

            $route['url'] = self::publicUrl($route['route'], $adminUrl);
            $route['csrf'] = self::csrfMode($route);
            $route['issues'] = self::routeIssues($route, $imports);
        }
        unset($route);

        return $routes;
    }

    /**
     * Las rutas se declaran como /admin/... y el Router las sirve bajo `admin.custom_url`.
     */
    private static function publicUrl(string $route, string $adminUrl): string
    {
        if ($adminUrl === '' || $adminUrl === 'admin') {
            return $route;
        }

        return $route === '/admin' || str_starts_with($route, '/admin/')
            ? '/' . $adminUrl . substr($route, 6)
            : $route;
    }

    /**
     * El Router valida CSRF en toda ruta que modifica estado, salvo las marcadas con withoutCsrf().
     *
     * @param array<string, mixed> $route
     */
    private static function csrfMode(array $route): string
    {
        if ($route['method'] === 'GET' || $route['method'] === 'QUERY') {
            return 'none';
        }

        return $route['withoutCsrf'] === true ? 'exempt' : 'protected';
    }

    /**
     * Mapa `NombreCorto => Clase\Completa` de las sentencias `use` del archivo, más su namespace
     * en la clave vacía.
     *
     * @return array<string, string>
     */
    private static function parseImports(string $source): array
    {
        $imports = ['' => ''];
        if (preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $namespace) === 1) {
            $imports[''] = $namespace[1];
        }

        preg_match_all('/^\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $segments = explode('\\', $match[1]);
            $imports[$match[2] ?? end($segments)] = ltrim($match[1], '\\');
        }

        return $imports;
    }

    /**
     * Resuelve `Clase::class` tal como lo haría PHP dentro del archivo de rutas.
     *
     * @param array<string, string> $imports
     */
    private static function resolveClass(string $reference, array $imports): string
    {
        $name = preg_replace('/::class$/', '', trim($reference)) ?? '';
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        if (isset($imports[$segments[0]])) {
            $segments[0] = $imports[$segments[0]];

            return implode('\\', $segments);
        }

        return ltrim($imports[''] . '\\' . $name, '\\');
    }

    /**
     * Problemas de una ruta. `error`: la ruta fallará al usarse (clase o método inexistente).
     * `warning`: le falta documentación.
     *
     * @param array<string, mixed> $route
     * @param array<string, string> $imports
     *
     * @return array<int, array{level: string, message: string}>
     */
    private static function routeIssues(array $route, array $imports): array
    {
        $issues = [];
        $missing = static function (string $reference, string $method) use ($imports): ?string {
            $class = self::resolveClass($reference, $imports);
            if (!class_exists($class)) {
                return "la clase {$class} no existe";
            }

            return method_exists($class, $method) ? null : "el método {$class}::{$method}() no existe";
        };

        if ($route['class'] === null) {
            $issues[] = ['level' => 'error', 'message' => 'No se pudo determinar el controlador: debe ser [Clase::class, \'metodo\'].'];
        } elseif (($problem = $missing($route['class'], (string) $route['class_method'])) !== null) {
            $issues[] = ['level' => 'error', 'message' => 'Controlador: ' . $problem . '.'];
        }

        foreach ($route['middleware'] as $middleware) {
            if (($problem = $missing($middleware['class'], $middleware['class_method'])) !== null) {
                $issues[] = ['level' => 'error', 'message' => 'Middleware: ' . $problem . '.'];
            }
        }

        if ($route['description'] === '') {
            $issues[] = ['level' => 'warning', 'message' => 'Falta `// description:`.'];
        }

        $documented = array_column($route['pathParams'], 'name');
        foreach (array_diff($route['routeParams'], $documented) as $param) {
            $issues[] = ['level' => 'warning', 'message' => "Falta `// path-param name:{$param} ...`."];
        }
        foreach (array_diff($documented, $route['routeParams']) as $param) {
            $issues[] = ['level' => 'warning', 'message' => "`path-param {$param}` no aparece en la URL de la ruta."];
        }

        return $issues;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<int, array{prefix: string, path: string, description: string}> $groups
     * @param array<int, array{class: string, class_method: string}> $inheritedMiddleware
     *
     * @return array<int, array<string, mixed>>
     */
    private static function extractRoutesFromTokens(
        array $tokens,
        int $start,
        int $end,
        array $groups,
        array $inheritedMiddleware,
    ): array {
        $routes = [];
        $documentation = self::emptyRouteDocumentation();

        for ($index = $start; $index < $end; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_COMMENT) {
                self::captureRouteDocumentation($token[1], $documentation);
                continue;
            }

            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'Router') {
                continue;
            }

            $scopeIndex = self::nextSignificantTokenIndex($tokens, $index + 1, $end);
            if (
                $scopeIndex === null
                || !is_array($tokens[$scopeIndex])
                || $tokens[$scopeIndex][0] !== T_DOUBLE_COLON
            ) {
                continue;
            }

            $methodIndex = self::nextSignificantTokenIndex($tokens, $scopeIndex + 1, $end);
            if ($methodIndex === null || !is_array($tokens[$methodIndex]) || $tokens[$methodIndex][0] !== T_STRING) {
                continue;
            }

            $method = strtolower($tokens[$methodIndex][1]);
            if (!in_array($method, ['group', 'get', 'post', 'put', 'patch', 'delete', 'query'], true)) {
                continue;
            }

            $openIndex = self::nextSignificantTokenIndex($tokens, $methodIndex + 1, $end);
            if ($openIndex === null || $tokens[$openIndex] !== '(') {
                continue;
            }

            $closeIndex = self::findMatchingToken($tokens, $openIndex, '(', ')', $end);
            if ($closeIndex === null) {
                continue;
            }

            $arguments = self::splitTokenArgumentRanges($tokens, $openIndex + 1, $closeIndex);
            $routePath = isset($arguments[0])
                ? self::getStringArgument($tokens, $arguments[0][0], $arguments[0][1])
                : null;

            if ($method === 'group') {
                $callbackRange = $arguments[1] ?? null;
                $callbackFunctionIndex = $callbackRange === null
                    ? null
                    : self::findTokenId($tokens, T_FUNCTION, $callbackRange[0], $callbackRange[1]);
                if ($routePath === null || $callbackFunctionIndex === null) {
                    $documentation = self::emptyRouteDocumentation();
                    $index = $closeIndex;
                    continue;
                }

                $bodyOpenIndex = self::findTokenValue($tokens, '{', $callbackFunctionIndex + 1, $callbackRange[1]);
                $bodyCloseIndex = $bodyOpenIndex === null
                    ? null
                    : self::findMatchingToken($tokens, $bodyOpenIndex, '{', '}', $callbackRange[1]);
                if ($bodyOpenIndex === null || $bodyCloseIndex === null) {
                    $documentation = self::emptyRouteDocumentation();
                    $index = $closeIndex;
                    continue;
                }

                $groupPath = ($groups === [] ? '' : $groups[count($groups) - 1]['path']) . $routePath;
                $nestedGroups = array_merge($groups, [[
                    'prefix' => $routePath,
                    'path' => $groupPath,
                    'description' => $documentation['description'],
                ]]);
                $groupMiddleware = isset($arguments[2])
                    ? self::parseMiddleware(self::tokenRangeToString($tokens, $arguments[2][0], $arguments[2][1]))
                    : [];
                $routes = array_merge(
                    $routes,
                    self::extractRoutesFromTokens(
                        $tokens,
                        $bodyOpenIndex + 1,
                        $bodyCloseIndex,
                        $nestedGroups,
                        array_merge($inheritedMiddleware, $groupMiddleware),
                    ),
                );
                $documentation = self::emptyRouteDocumentation();
                $index = $closeIndex;
                continue;
            }

            if ($routePath === null) {
                $documentation = self::emptyRouteDocumentation();
                $index = $closeIndex;
                continue;
            }

            $fullPath = ($groups === [] ? '' : $groups[count($groups) - 1]['path']) . $routePath;
            preg_match_all('/\{([^}]+)\}/', $fullPath, $routeParamMatches);

            $callbackRange = $arguments[1] ?? null;
            $callback = $callbackRange === null
                ? ''
                : self::tokenRangeToString($tokens, $callbackRange[0], $callbackRange[1]);
            $class = null;
            $classMethod = null;
            if (preg_match('/\[\s*([\\\\\w]+::class)\s*,\s*([\'"])(.*?)\2\s*,?\s*\]/s', $callback, $callbackMatches)) {
                $class = $callbackMatches[1];
                $classMethod = $callbackMatches[3];
            }

            $routeMiddleware = isset($arguments[2])
                ? self::parseMiddleware(self::tokenRangeToString($tokens, $arguments[2][0], $arguments[2][1]))
                : [];
            // Métodos encadenados a la ruta: ->middleware([...]) y ->withoutCsrf(), en cualquier orden
            $withoutCsrf = false;
            $chainIndex = $closeIndex;
            while (true) {
                $operatorIndex = self::nextSignificantTokenIndex($tokens, $chainIndex + 1, $end);
                if (
                    $operatorIndex === null
                    || !is_array($tokens[$operatorIndex])
                    || $tokens[$operatorIndex][0] !== T_OBJECT_OPERATOR
                ) {
                    break;
                }

                $nameIndex = self::nextSignificantTokenIndex($tokens, $operatorIndex + 1, $end);
                $argsOpenIndex = $nameIndex === null ? null : self::nextSignificantTokenIndex($tokens, $nameIndex + 1, $end);
                if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $argsOpenIndex === null || $tokens[$argsOpenIndex] !== '(') {
                    break;
                }

                $argsCloseIndex = self::findMatchingToken($tokens, $argsOpenIndex, '(', ')', $end);
                if ($argsCloseIndex === null) {
                    break;
                }

                if ($tokens[$nameIndex][1] === 'withoutCsrf') {
                    $withoutCsrf = true;
                } elseif ($tokens[$nameIndex][1] === 'middleware') {
                    $chainArguments = self::splitTokenArgumentRanges($tokens, $argsOpenIndex + 1, $argsCloseIndex);
                    if (isset($chainArguments[0])) {
                        $routeMiddleware = array_merge(
                            $routeMiddleware,
                            self::parseMiddleware(self::tokenRangeToString($tokens, $chainArguments[0][0], $chainArguments[0][1])),
                        );
                    }
                }

                $chainIndex = $argsCloseIndex;
            }

            $description = $documentation['description'];
            // Sin descripción propia hereda la del grupo más cercano que tenga una
            for ($level = count($groups) - 1; $description === '' && $level >= 0; $level--) {
                $description = $groups[$level]['description'];
            }

            $routes[] = [
                'route' => $fullPath,
                'method' => strtoupper($method),
                'routeParams' => $routeParamMatches[1] ?? [],
                'description' => $description,
                'pathParams' => $documentation['pathParams'],
                'requestBody' => $documentation['requestBody'],
                'queryParams' => $documentation['queryParams'],
                'class' => $class,
                'class_method' => $classMethod,
                'middleware' => array_merge($inheritedMiddleware, $routeMiddleware),
                'withoutCsrf' => $withoutCsrf,
                'groups' => $groups,
            ];
            $documentation = self::emptyRouteDocumentation();
            $index = $closeIndex;
        }

        return $routes;
    }

    /**
     * @param array<string, mixed> $documentation
     */
    private static function captureRouteDocumentation(string $comment, array &$documentation): void
    {
        if (preg_match('/^\s*\/\/\s*description:\s*(.+)$/', $comment, $matches)) {
            $documentation['description'] = trim($matches[1]);
            return;
        }

        foreach (['path-param' => 'pathParams', 'request-body' => 'requestBody', 'query-param' => 'queryParams'] as $tag => $key) {
            if (preg_match('/^\s*\/\/\s*' . preg_quote($tag, '/') . '\s+name:(.+?)\s+type:(.+?)\s+description:(.+)$/', $comment, $matches)) {
                $documentation[$key][] = [
                    'name' => trim($matches[1]),
                    'type' => trim($matches[2]),
                    'description' => trim($matches[3]),
                ];
                return;
            }
        }
    }

    /**
     * @return array{description: string, pathParams: list<array{name: string, type: string, description: string}>, requestBody: list<array{name: string, type: string, description: string}>, queryParams: list<array{name: string, type: string, description: string}>}
     */
    private static function emptyRouteDocumentation(): array
    {
        return [
            'description' => '',
            'pathParams' => [],
            'requestBody' => [],
            'queryParams' => [],
        ];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificantTokenIndex(array $tokens, int $start, int $end): ?int
    {
        for ($index = $start; $index < $end; $index++) {
            if (is_array($tokens[$index]) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findMatchingToken(
        array $tokens,
        int $openIndex,
        string $openToken,
        string $closeToken,
        int $end,
    ): ?int {
        $depth = 0;
        for ($index = $openIndex; $index < $end; $index++) {
            if ($tokens[$index] === $openToken) {
                $depth++;
            } elseif ($tokens[$index] === $closeToken) {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function splitTokenArgumentRanges(array $tokens, int $start, int $end): array
    {
        $ranges = [];
        $argumentStart = $start;
        $parentheses = 0;
        $brackets = 0;
        $braces = 0;

        for ($index = $start; $index < $end; $index++) {
            $token = $tokens[$index];
            if ($token === '(') {
                $parentheses++;
            } elseif ($token === ')') {
                $parentheses--;
            } elseif ($token === '[') {
                $brackets++;
            } elseif ($token === ']') {
                $brackets--;
            } elseif ($token === '{') {
                $braces++;
            } elseif ($token === '}') {
                $braces--;
            } elseif ($token === ',' && $parentheses === 0 && $brackets === 0 && $braces === 0) {
                $ranges[] = [$argumentStart, $index];
                $argumentStart = $index + 1;
            }
        }

        if ($argumentStart < $end) {
            $ranges[] = [$argumentStart, $end];
        }

        return $ranges;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findTokenId(array $tokens, int $tokenId, int $start, int $end): ?int
    {
        for ($index = $start; $index < $end; $index++) {
            if (is_array($tokens[$index]) && $tokens[$index][0] === $tokenId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function findTokenValue(array $tokens, string $value, int $start, int $end): ?int
    {
        for ($index = $start; $index < $end; $index++) {
            if ($tokens[$index] === $value) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function getStringArgument(array $tokens, int $start, int $end): ?string
    {
        $index = self::nextSignificantTokenIndex($tokens, $start, $end);
        if ($index === null || !is_array($tokens[$index]) || $tokens[$index][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $literal = $tokens[$index][1];
        $value = substr($literal, 1, -1);
        return $literal[0] === "'"
            ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value)
            : stripcslashes($value);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenRangeToString(array $tokens, int $start, int $end): string
    {
        $value = '';
        for ($index = $start; $index < $end; $index++) {
            // Sin comentarios: un middleware comentado dentro del arreglo no está activo
            if (is_array($tokens[$index]) && in_array($tokens[$index][0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $value .= is_array($tokens[$index]) ? $tokens[$index][1] : $tokens[$index];
        }

        return $value;
    }

    public static function listarRutasFromRoute(string $routeFile): void
    {
        self::printRouteTable(self::extractRouteData($routeFile));
    }

    /**
     * Lista las rutas de todos los archivos, sin preguntar (`route:list --all`).
     */
    public static function listAllRoutes(): void
    {
        $total = 0;
        foreach (self::extractAllRouteData() as $routeFile => $routes) {
            echo PHP_EOL . CLIErrorHandler::colorize($routeFile, 'cyan') . PHP_EOL;
            self::printRouteTable($routes);
            $total += count($routes);
        }

        echo PHP_EOL;
        CLIErrorHandler::info($total . ' rutas.');
    }

    /**
     * Revisa todas las rutas (`route:check`). Los errores (controlador, método o middleware que no
     * existe) marcan la ejecución como fallida; con `--strict` también la documentación faltante.
     */
    public static function checkRoutes(bool $strict = false): void
    {
        $errors = 0;
        $warnings = 0;
        $total = 0;

        foreach (self::extractAllRouteData() as $routeFile => $routes) {
            foreach ($routes as $route) {
                $total++;
                foreach ($route['issues'] as $issue) {
                    $isError = $issue['level'] === 'error';
                    $errors += $isError ? 1 : 0;
                    $warnings += $isError ? 0 : 1;
                    echo sprintf(
                        '%s %s %s %s — %s%s',
                        $isError ? CLIErrorHandler::colorize('✗', 'red') : CLIErrorHandler::colorize('⚠', 'yellow'),
                        $routeFile,
                        $route['method'],
                        $route['route'],
                        $issue['message'],
                        PHP_EOL,
                    );
                }
            }
        }

        $summary = sprintf('%d rutas revisadas: %d error(es), %d aviso(s) de documentación.', $total, $errors, $warnings);
        if ($errors > 0 || ($strict && $warnings > 0)) {
            CLIErrorHandler::warning($summary);
            CLIErrorHandler::$failed = true;

            return;
        }

        CLIErrorHandler::success($summary);
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     */
    private static function printRouteTable(array $routes): void
    {
        echo str_pad('Método', 9) . str_pad('URL', 52) . str_pad('Controlador', 44) . 'CSRF' . PHP_EOL;
        echo str_repeat('-', 112) . PHP_EOL;

        foreach ($routes as $route) {
            $controller = $route['class'] === null
                ? '(no reconocido)'
                : str_replace('::class', '', (string) $route['class']) . '@' . $route['class_method'];

            echo str_pad($route['method'], 8) . ' '
                . str_pad($route['url'], 51) . ' '
                . str_pad($controller, 43) . ' '
                . ['none' => '-', 'protected' => 'sí', 'exempt' => 'EXENTA'][$route['csrf']]
                . PHP_EOL;
        }
    }

    /**
     * Analiza una cadena de middleware y la convierte en un array estructurado.
     *
     * @param string $middlewareStr Cadena de middleware
     *
     * @return array Array de middlewares
     */
    private static function parseMiddleware(string $middlewareStr): array
    {
        $middlewares = [];

        // Limpiar espacios y caracteres innecesarios
        $middlewareStr = preg_replace('/\s+/', '', $middlewareStr);

        // Manejar diferentes formatos de middleware
        if (str_starts_with($middlewareStr, '[[')) {
            // Formato [[Class,'method'],[Class,'method']]
            $items = explode('],[', substr($middlewareStr, 2, -2));
        } elseif (str_starts_with($middlewareStr, '[')) {
            // Formato [Class,'method']
            $items = [substr($middlewareStr, 1, -1)];
        } else {
            // Otros formatos
            $items = explode('],[', $middlewareStr);
        }

        foreach ($items as $item) {
            if ($item === '' || $item === '0') {
                continue;
            }

            // Extraer clase y método
            $parts = explode(',', $item);
            if (count($parts) >= 2) {
                $class = trim($parts[0], "'[]");
                $method = trim($parts[1], "'[]");

                $middlewares[] = [
                    'class' => $class,
                    'class_method' => $method,
                ];
            }
        }

        return $middlewares;
    }
}
