<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Composer\InstalledVersions;
use Error;
use Exception;
use RuntimeException;
use Throwable;

/**
 * The Router class handles routing and middleware functionality for the application.
 */
class Router
{
    /** Métodos con tabla de rutas propia (HEAD usa la de GET; OPTIONS lo responde init_headers) */
    private const array METHODS = ['get', 'post', 'put', 'patch', 'delete', 'query'];

    // Stacks para prefijos y middlewares de grupo
    protected static array $groupPrefixes = [];

    protected static array $groupMiddlewares = [];

    protected static array $getRoutes = [];

    protected static array $postRoutes = [];

    protected static array $putRoutes = [];

    protected static array $patchRoutes = [];

    protected static array $deleteRoutes = [];

    /** @var array<string, mixed> */
    protected static array $queryRoutes = [];

    protected static array $middlewares = []; // Estructura: $middlewares[method][route][]

    /** @var array<string, array<string, true>> Rutas que renuncian a la validación CSRF automática */
    protected static array $csrfExempt = [];

    protected string $lastRoute = '';

    protected string $lastRouteMethod = '';

    /**
     * Carga las rutas de la aplicación desde los archivos de $path (y sus subcarpetas).
     */
    public static function loadRoutes(string $path): void
    {
        self::loadRoutesFrom($path);
    }

    /**
     * Firma que habilita el detalle de /up. Solo la puede calcular quien tiene config.json
     * (app:check:deploy en el servidor); vacía si no hay session.key.
     */
    public static function healthToken(): string
    {
        global $config;

        $key = (string) ($config['session']['key'] ?? '');

        return $key === '' ? '' : hash_hmac('sha256', 'versawys:/up', $key);
    }

    /**
     * `/up`: verificación de vida para el despliegue y los monitores. A cualquiera le responde solo
     * `ok`. Con la cabecera X-Versawys-Health firmada (healthToken) agrega cómo corre PHP en el
     * servidor web (FPM/Apache), que puede diferir del PHP de la terminal donde corre app:check:deploy.
     * No se decide por IP: detrás de un proxy en el mismo servidor todas las visitas llegan desde
     * 127.0.0.1.
     *
     * @return array<string, mixed>
     */
    public static function health(): array
    {
        $token = self::healthToken();
        if ($token === '' || !hash_equals($token, $_SERVER['HTTP_X_VERSAWYS_HEALTH'] ?? '')) {
            return ['status' => 'ok'];
        }

        $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

        return [
            'status' => 'ok',
            'kernel' => InstalledVersions::isInstalled('versawys/kernel')
                ? InstalledVersions::getPrettyVersion('versawys/kernel')
                : 'dev',
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'opcache' => is_array($opcache) && ($opcache['opcache_enabled'] ?? false) === true,
            'opcache_validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
            // Con xdebug.mode=off la extensión sigue cargada pero no hace nada; ini_get devuelve '' en ese caso
            'xdebug' => function_exists('xdebug_info')
                ? xdebug_info('mode') !== []
                : !in_array((string) ini_get('xdebug.mode'), ['', 'off'], true),
        ];
    }

    /**
     * Normalizes a URL by removing leading and trailing slashes and extracting the path.
     * Ahora incluye sanitización de seguridad contra inyección SQL.
     *
     * @param string $url the URL to be normalized
     *
     * @return string the normalized URL path
     */
    public static function normalize_url(string $url): string
    {
        // Aplicar sanitización de seguridad
        return SecurityUtils::validateAndCleanUrl($url);
    }

    /**
     * Agrupa rutas con un prefijo y/o middlewares compartidos.
     *
     * @param string $prefix Prefijo de ruta para el grupo
     * @param callable $callback Función que define las rutas del grupo
     * @param array $middlewares Middlewares compartidos para todas las rutas del grupo
     */
    public static function group(string $prefix, callable $callback, array $middlewares = []): void
    {
        // Guardar el estado actual antes de modificarlo
        $currentGroupPrefixes = self::$groupPrefixes;
        $currentGroupMiddlewares = self::$groupMiddlewares;

        // Añadir el nuevo prefijo y middlewares
        self::$groupPrefixes[] = $prefix;
        self::$groupMiddlewares[] = $middlewares;

        // Ejecutar la función que define las rutas del grupo
        $callback();

        // Restaurar el estado anterior (desapilar)
        self::$groupPrefixes = $currentGroupPrefixes;
        self::$groupMiddlewares = $currentGroupMiddlewares;
    }

    public static function get(string $route, mixed $callback, array $middlewares = []): self
    {
        return self::add('get', $route, $callback, $middlewares);
    }

    public static function post(string $route, mixed $callback, array $middlewares = []): self
    {
        return self::add('post', $route, $callback, $middlewares);
    }

    public static function put(string $route, mixed $callback, array $middlewares = []): self
    {
        return self::add('put', $route, $callback, $middlewares);
    }

    public static function patch(string $route, mixed $callback, array $middlewares = []): self
    {
        return self::add('patch', $route, $callback, $middlewares);
    }

    public static function delete(string $route, mixed $callback, array $middlewares = []): self
    {
        return self::add('delete', $route, $callback, $middlewares);
    }

    /**
     * Método HTTP QUERY: una consulta segura e idempotente con cuerpo (filtros, búsquedas). Como
     * GET, no exige CSRF. `php -S` no lo acepta: ahí se envía como POST con la cabecera
     * `X-HTTP-Method-Override: QUERY` (o el campo `_method`).
     */
    /**
     * @param array<int, array{0: class-string, 1: string}> $middlewares
     */
    public static function query(string $route, mixed $callback, array $middlewares = []): self
    {
        return self::add('query', $route, $callback, $middlewares);
    }

    /**
     * Adds middleware to be executed for the last registered route.
     *
     * @param array $middlewares the array of middleware classes and methods
     *
     * @return Router the Router instance
     */
    /**
     * Excluye la ruta de la validación CSRF automática. Solo para endpoints que autentican la
     * petición por otro medio (webhooks firmados, APIs con token propio).
     */
    public function withoutCsrf(): static
    {
        self::$csrfExempt[$this->lastRouteMethod][$this->lastRoute] = true;

        return $this;
    }

    public function middleware(array $middlewares): static
    {
        $route = $this->lastRoute;
        $method = $this->lastRouteMethod;
        if ($route && $method) {
            // Combina con los middlewares de grupo si existen
            $allMiddlewares = self::mergeMiddlewares($middlewares);
            self::$middlewares[$method][$route] = $allMiddlewares;
        }

        return $this;
    }

    /**
     * Resolves the request URL and finds the appropriate route callback to execute.
     *
     * @return mixed the result of executing the route callback
     */
    public function resolve(): mixed
    {
        // Control de tiempo máximo de ejecución para prevenir bucles infinitos
        $startTime = microtime(true);
        $maxExecutionTime = 5; // 5 segundos máximo

        global $request, $config;

        try {
            // 🔒 VALIDAR MÉTODOS HTTP PERMITIDOS PRIMERO
            $method = strtolower($request->getMethod());
            $allowedMethods = ['get', 'post', 'put', 'patch', 'delete', 'query', 'options', 'head'];

            if (!in_array($method, $allowedMethods, true)) {
                // Método no permitido, responder 405
                Output::status(405);
                Output::header('Allow: ' . implode(', ', array_map('strtoupper', $allowedMethods)));

                return Response::jsonError([
                    'success' => 0,
                    'message' => 'Method Not Allowed',
                    'allowed_methods' => array_map('strtoupper', $allowedMethods),
                ], 405);
            }

            // �🚨 RATE LIMITING GLOBAL - Aplicar antes de procesar rutas
            if ($this->shouldApplyGlobalRateLimit($request)) {
                $rateLimitResult = SecurityUtils::checkGlobalRateLimit($request);
                if ($rateLimitResult !== true) {
                    return Response::jsonError($rateLimitResult, 429);
                }
            }

            $url = self::normalize_url($request->getUrl());

            // app:down: 503 para todos, salvo quien entró con la clave
            if (Maintenance::handle($request, $url)) {
                return true;
            }

            if ($url === '/up' && in_array($method, ['get', 'head'], true)) {
                return Response::json(self::health(), 200);
            }

            // Instalación sin terminar: todo lleva al asistente, nada cae en el login
            if (
                Installation::isPending()
                && $url !== Installation::PATH
                && !str_starts_with($url, Installation::PATH . '/')
                && !str_starts_with($url, '/_profiler')
            ) {
                if ($request->isApiCall()) {
                    return Response::json([
                        'success' => 0,
                        'message' => 'La aplicación aún no está instalada.',
                        'redirect' => Installation::PATH,
                    ], 503);
                }

                Response::redirect(Installation::PATH);

                return true;
            }

            // Panel del profiler: solo en modo debug y desde la propia máquina (o profiler.allowed_ips)
            if (($url === '/_profiler' || str_starts_with($url, '/_profiler/')) && Profiler::enabled()) {
                if (!Profiler::allowed($request->getIp())) {
                    return $this->respondNotFound($request, $config, 'Página no encontrada');
                }

                $id = $url === '/_profiler' ? null : substr($url, strlen('/_profiler/'));
                // El probador de /doc pide la petición para repetirla
                if ($id !== null && str_ends_with($id, '.request.json')) {
                    $replay = Profiler::replayFor(substr($id, 0, -13));

                    return Response::json($replay ?? ['success' => 0], $replay === null ? 404 : 200);
                }

                // La barra pide el resumen de cada navegación del SPA
                if ($id !== null && str_ends_with($id, '.json')) {
                    $summary = Profiler::summaryFor(substr($id, 0, -5));

                    return Response::json($summary ?? ['success' => 0], $summary === null ? 404 : 200);
                }

                echo Profiler::panel($id);

                return true;
            }

            $customAdminUrl = $config['admin']['custom_url'] ?? 'admin';
            $hideDefault = $config['admin']['hide_default'] ?? false;

            // Bloquear acceso a /admin/ si está oculto
            if ($hideDefault && (str_starts_with($url, '/admin/') || $url === '/admin')) {
                return $this->respondNotFound($request, $config, 'Página no encontrada');
            }

            // Mapear URL personalizada a admin
            if (str_starts_with($url, sprintf('/%s/', $customAdminUrl))) {
                // Solo el prefijo: la URL del panel puede repetirse más adelante en la ruta
                $url = '/admin/' . substr($url, strlen($customAdminUrl) + 2);
            } elseif ($url === '/' . $customAdminUrl) {
                $url = '/admin';
            }

            // Si es un archivo no se ejecuta el router
            if (
                preg_match(
                    '/\.(js|css|jpg|jpeg|png|gif|svg|pdf|json|csv|xlsx|ico|woff|woff2|ttf|otf|webp|avif)$/',
                    $url,
                )
                || str_starts_with($url, 'blob:')
                || str_starts_with($url, 'data:')
            ) {
                // Un archivo estático que llegó hasta aquí no existe o está protegido: nunca 200 vacío
                Output::status(404);

                return null;
            }

            if ($method === 'query') {
                // RFC del método QUERY: los tipos de cuerpo que se aceptan
                Output::header('Accept-Query: application/json, application/x-www-form-urlencoded');
            }

            // Las peticiones HEAD deben usar las rutas GET (estándar HTTP)
            // HEAD es idéntico a GET pero sin cuerpo de respuesta
            $routeMethod = $method === 'head' ? 'get' : $method;
            $routes = in_array($routeMethod, self::METHODS, true) ? self::routeTable($routeMethod) : [];

            // === OPTIMIZACIÓN: Validación rápida de prefijos ===
            // Extraer todos los prefijos de las rutas registradas (incluyendo rutas parametrizadas)
            $prefixes = [];
            foreach (array_keys($routes) as $routePattern) {
                // Extraer el primer segmento de cada ruta (después del slash inicial)
                $parts = explode('/', ltrim($routePattern, '/'));
                if (isset($parts[0]) && ($parts[0] !== '' && $parts[0] !== '0')) {
                    $prefixes[] = $parts[0];
                }
            }

            $prefixes = array_unique($prefixes);

            // Extraer el primer segmento de la URL solicitada
            $urlParts = explode('/', ltrim($url, '/'));
            $urlPrefix = $urlParts[0] ?? '';

            // Si la URL no inicia con ningún prefijo registrado, retornar 404 inmediatamente
            if (!in_array($urlPrefix, $prefixes, true) && ($urlPrefix !== '' && $urlPrefix !== '0')) {
                return $this->respondNotFound($request, $config);
            }

            // Optimización: primero intentar coincidencia exacta (más rápido)
            // no rutas dinamicas
            if (isset($routes[$url])) {
                $callback = $routes[$url];
                $originalRoute = $url;
                // Ejecutar middlewares y controlador de forma centralizada
                $result = null;
                $routeMiddlewares = $this->routeMiddlewares($routeMethod, $originalRoute);
                if ($routeMiddlewares !== []) {
                    $result = $this->executeMiddlewares($routeMiddlewares, []);
                }

                if ($result !== null) {
                    return $result;
                }

                return $this->executeController($callback, []);
            }

            // Si no hay coincidencia exacta, buscar rutas con parámetros
            // Precalcular cantidad de segmentos de la URL solicitada
            // Sin callback, array_filter() descartaría el segmento "0" (/usuarios/0): se cuentan los no vacíos
            $segments = static fn(string $path): int => count(array_filter(
                explode('/', trim($path, '/')),
                static fn(string $segment): bool => $segment !== '',
            ));
            $urlSegmentCount = $segments($url);
            foreach ($routes as $route => $callback) {
                // Verificar tiempo de ejecución excesivo
                if ((microtime(true) - $startTime) > $maxExecutionTime) {
                    return Response::jsonError([
                        'success' => 0,
                        'message' => 'Tiempo de ejecución excedido',
                    ], 500);
                }

                // Saltar rutas sin parámetros (ya verificamos coincidencia exacta)
                if (!str_contains($route, '{')) {
                    continue;
                }

                // Optimización: solo intentar match si el número de segmentos coincide
                $routeSegmentCount = $segments($route);
                if ($routeSegmentCount !== $urlSegmentCount) {
                    continue;
                }

                $originalRoute = $route;

                try {
                    // Permitir guiones en nombres de parámetros y valores
                    $route = preg_replace('#{[a-zA-Z0-9_]+}#', '([a-zA-Z0-9_\-]+)', $route);
                } catch (RequestHalted $halted) {
                    // Fin de la petición en pruebas (Output::halt): no es un error de la aplicación
                    throw $halted;
                } catch (Throwable $e) {
                    if (!empty($config['build']['debug'])) {
                        Loggin::error('Error al procesar patrón de ruta: ' . $e->getMessage(), 'router', [
                            'timestamp' => date('Y-m-d H:i:s'),
                            'request_uri' => $request->getUrl(),
                            'request_method' => $request->getMethod(),
                            'client_ip' => $request->getIp(),
                        ]);
                    }

                    continue; // Saltar esta ruta si hay un error
                }

                if (preg_match(sprintf('#^%s$#', $route), $url, $matches)) {
                    $slug = array_slice($matches, 1);

                    // Ejecutar middlewares y controlador de forma centralizada para rutas parametrizadas
                    $result = null;
                    $routeMiddlewares = $this->routeMiddlewares($routeMethod, $originalRoute);
                    if ($routeMiddlewares !== []) {
                        $result = $this->executeMiddlewares($routeMiddlewares, $slug);
                    }

                    if ($result !== null) {
                        return $result;
                    }

                    return $this->executeController($callback, $slug);
                }
            }

            // Manejo de ruta no encontrada - pero primero verificar si la ruta existe con otro método
            return $this->handleRouteNotFound($request, $config, $url, $method);
        } catch (Exception $e) {
            self::catch($e);
        } catch (RequestHalted $halted) {
            // Fin de la petición en pruebas (Output::halt): no es un error de la aplicación
            throw $halted;
        } catch (Throwable $th) {
            self::catch($th);
        }

        return false;
    }

    private static function loadRoutesFrom(string $path): void
    {
        $files = is_dir($path) ? scandir($path) : false;
        if ($files === false) {
            self::routeLoadError('Cannot read routes directory: ' . $path);

            return;
        }

        foreach (array_diff($files, ['.', '..']) as $file) {
            $fullPath = $path . '/' . $file;
            if (is_dir($fullPath)) {
                self::loadRoutesFrom($fullPath);
            } else {
                self::requireRoutes($fullPath);
            }
        }
    }

    private static function requireRoutes(string $file): bool
    {
        try {
            require_once $file;

            return true;
        } catch (RequestHalted $halted) {
            // Fin de la petición en pruebas (Output::halt): no es un error de la aplicación
            throw $halted;
        } catch (Throwable $throwable) {
            self::routeLoadError(sprintf("Error loading route file '%s': %s", $file, $throwable->getMessage()));

            return false;
        }
    }

    /**
     * En producción un archivo de rutas roto se registra y se sigue con el resto; en debug se muestra.
     */
    private static function routeLoadError(string $message): void
    {
        global $config;

        error_log($message);
        if ($config['build']['debug'] ?? false) {
            ErrorHandler::handleException(new RuntimeException($message));
        }
    }

    /**
     * Tabla de rutas de un método HTTP (por referencia, para registrar).
     *
     * @return array<string, mixed>
     */
    private static function &routeTable(string $method): array
    {
        switch ($method) {
            case 'post':
                return self::$postRoutes;
            case 'put':
                return self::$putRoutes;
            case 'patch':
                return self::$patchRoutes;
            case 'delete':
                return self::$deleteRoutes;
            case 'query':
                return self::$queryRoutes;
            default:
                return self::$getRoutes;
        }
    }

    /**
     * Registra una ruta con los prefijos y middlewares de los grupos que la contienen.
     *
     * @param array<int, array{0: class-string, 1: string}> $middlewares
     */
    private static function add(string $method, string $route, mixed $callback, array $middlewares): self
    {
        $fullRoute = implode('', self::$groupPrefixes) . $route;
        $routes = &self::routeTable($method);
        $routes[$fullRoute] = $callback;
        $allMiddlewares = self::mergeMiddlewares($middlewares);
        if ($allMiddlewares !== []) {
            self::$middlewares[$method][$fullRoute] = $allMiddlewares;
        }

        $instance = new static();
        $instance->lastRoute = $fullRoute;
        $instance->lastRouteMethod = $method;

        return $instance;
    }

    /**
     * Middlewares de una ruta. Toda ruta que modifica estado (POST, PUT, PATCH, DELETE) valida
     * primero el token CSRF, salvo que lo declare ella misma o use withoutCsrf().
     *
     * @return array<int, array{0: class-string, 1: string}>
     */
    private function routeMiddlewares(string $method, string $route): array
    {
        $middlewares = self::$middlewares[$method][$route] ?? [];

        // GET y QUERY no cambian estado: no llevan CSRF
        if ($method === 'get' || $method === 'query' || isset(self::$csrfExempt[$method][$route])) {
            return $middlewares;
        }

        foreach ($middlewares as $middleware) {
            if (($middleware[1] ?? '') === 'validateCSRFToken') {
                return $middlewares;
            }
        }

        return [[GlobalMiddleware::class, 'validateCSRFToken'], ...$middlewares];
    }

    /**
     * Registers a GET route with the specified route and callback.
     *
     * @param string $route the route pattern
     * @param mixed $callback the callback function or method to be executed
     *
     * @return Router the Router instance
     */
    /**
     * Combina los middlewares de grupo y de ruta de forma robusta.
     */
    private static function mergeMiddlewares(array $middlewares = []): array
    {
        $mergeArrays = array_merge(self::$groupMiddlewares ?: [], [is_array($middlewares) ? $middlewares : []]);

        return array_merge(...$mergeArrays);
    }

    /**
     * Handles the catching and handling of exceptions in the Router class.
     *
     * @param Exception|Throwable $e the exception to be caught and handled
     */
    private function catch(Exception|Error $e): void
    {
        global $config, $request;

        if ($config['build']['debug']) {
            Response::jsonError([
                'success' => 0,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ], 500);
        } else {
            Loggin::error(
                'Error en el router: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine(),
                'router',
                [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'request_uri' => $request->getUrl(),
                    'request_method' => $request->getMethod(),
                    'client_ip' => $request->getIp(),
                ],
            );
            $this->internalError();
        }
    }

    /**
     * Un fallo interno responde 500 también en HTML: un 404 lo escondía de los monitores y del usuario.
     */
    private function internalError(): bool|string
    {
        global $request;

        if ($request->isApiCall()) {
            return Response::json(['success' => 0, 'message' => 'Error interno'], 500);
        }

        return Response::jsonError(['success' => 0, 'message' => 'Error interno del servidor'], 500);
    }

    /**
     * Ejecuta los middlewares de forma centralizada.
     *
     * @param array<int, array{0: class-string, 1: string}> $middlewares
     */
    private function executeMiddlewares(array $middlewares, array $slug = []): bool|string|null
    {
        global $request, $config;
        foreach ($middlewares as $middleware) {
            try {
                [$middlewareClass, $middlewareMethodName] = $middleware;

                if (!class_exists($middlewareClass)) {
                    $errorMsg = sprintf("Middleware class '%s' no encontrada", $middlewareClass);

                    throw new Exception($errorMsg);
                }

                $middlewareClassTemp = Container::getInstance()->get($middlewareClass);
                if (!method_exists($middlewareClassTemp, $middlewareMethodName)) {
                    $errorMsg = sprintf(
                        "Método '%s' no encontrado en middleware '%s'",
                        $middlewareMethodName,
                        $middlewareClass,
                    );

                    throw new Exception($errorMsg);
                }

                $startedAt = hrtime(true);

                try {
                    $response = Container::getInstance()->call($middlewareClassTemp, $middlewareMethodName, $slug);
                } finally {
                    Profiler::span(
                        'middleware',
                        $middlewareClass . '::' . $middlewareMethodName,
                        (hrtime(true) - $startedAt) / 1e6,
                    );
                }

                if (is_array($response) || is_object($response)) {
                    $status = is_array($response) ? (int) ($response['code'] ?? 200) : 200;

                    return Response::json((array) $response, $status >= 100 && $status <= 599 ? $status : 200);
                }
            } catch (RequestHalted $halted) {
                // Fin de la petición en pruebas (Output::halt): no es un error de la aplicación
                throw $halted;
            } catch (NotFound) {
                return $this->respondNotFound($request, $config);
            } catch (Throwable $e) {
                Loggin::error(
                    'Error en middleware: '
                    . $e->getMessage()
                    . ' en '
                    . ($middlewareClass ?? 'desconocido')
                    . '::'
                    . ($middlewareMethodName ?? 'desconocido'),
                    'router',
                    [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'request_uri' => $request->getUrl(),
                        'request_method' => $request->getMethod(),
                        'client_ip' => $request->getIp(),
                    ],
                );

                // MEJORA: En modo debug, dejar que la excepción se propague al ErrorHandler global
                if (isset($config['build']['debug']) && $config['build']['debug']) {
                    throw $e; // Re-lanzar la excepción para ErrorHandler detallado
                }

                return $this->internalError();
            }
        }

        return null;
    }

    /**
     * Ejecuta el controlador de forma centralizada.
     */
    private function executeController($callback, array $slug = []): bool|string
    {
        global $request, $config;

        try {
            [$controllerClass, $controllerMethodName] = $callback;
            $controllerClassTemp = Container::getInstance()->get($controllerClass);
            if (!method_exists($controllerClassTemp, $controllerMethodName)) {
                throw new Exception(sprintf(
                    "Método '%s' no encontrado en controlador '%s'",
                    $controllerMethodName,
                    $controllerClass,
                ));
            }

            $startedAt = hrtime(true);

            try {
                $response = Container::getInstance()->call($controllerClassTemp, $controllerMethodName, $slug);
            } finally {
                Profiler::span(
                    'controller',
                    $controllerClass . '::' . $controllerMethodName,
                    (hrtime(true) - $startedAt) / 1e6,
                );
            }

            if (is_array($response) || is_object($response)) {
                // Igual que en los middlewares: la clave `code` define el estado HTTP
                // Solo si es un estado HTTP válido: hay respuestas con code 0 o con el código de una excepción
                $status = is_array($response) ? (int) ($response['code'] ?? 200) : 200;

                return Response::json((array) $response, $status >= 100 && $status <= 599 ? $status : 200);
            }

            echo $response;

            return true;
        } catch (RequestHalted $halted) {
            // Fin de la petición en pruebas (Output::halt): no es un error de la aplicación
            throw $halted;
        } catch (NotFound) {
            return $this->respondNotFound($request, $config);
        } catch (Throwable $throwable) {
            if (isset($controllerClass, $controllerMethodName)) {
                Loggin::error(
                    'Error en controlador: '
                    . $throwable->getMessage()
                    . ' en '
                    . $controllerClass
                    . '::'
                    . $controllerMethodName,
                    'router',
                    [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'request_uri' => $request->getUrl(),
                        'request_method' => $request->getMethod(),
                        'client_ip' => $request->getIp(),
                    ],
                );
            } else {
                Loggin::error('Error en controlador: ' . $throwable->getMessage(), 'router', [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'request_uri' => $request->getUrl(),
                    'request_method' => $request->getMethod(),
                    'client_ip' => $request->getIp(),
                ]);
            }

            // MEJORA: En modo debug, dejar que la excepción se propague al ErrorHandler global
            // para obtener información más detallada (stack trace, contexto, etc.)
            if (isset($config['build']['debug']) && $config['build']['debug']) {
                throw $throwable; // Re-lanzar la excepción para que la capture ErrorHandler::handleException
            }

            return $this->internalError();
        }
    }

    /**
     * Centraliza la respuesta para rutas no encontradas o errores internos.
     */
    private function respondNotFound($request, array $config, string $e = 'ruta no encontrada'): bool|string
    {
        // Un 404 no es un fallo del sistema: nivel notice, sin traza (los escáneres generan miles)
        Loggin::notice('Ruta no encontrada: ' . $request->getUrl(), 'router', [
            'timestamp' => date('Y-m-d H:i:s'),
            'request_uri' => $request->getUrl(),
            'request_method' => $request->getMethod(),
            'client_ip' => $request->getIp(),
        ]);

        // Detectar peticiones SPA usando el método centralizado de Request
        $isSPARequest = Request::isSPARequestStatic();

        if (!$request->isApiCall()) {
            // En modo debug con petición SPA, devolver error en formato JSON SPA
            if ($config['build']['debug'] && $isSPARequest) {
                $errorMessage = sprintf('%s -> %s::%s', $e, $request->getMethod(), $request->getUrl());
                $errorHtml =
                    '<div class="flex flex-col justify-center items-center px-6 mx-auto h-screen xl:px-0">'
                    . '<div class="text-center xl:max-w-4xl">'
                    . '<h1 class="mb-3 text-2xl font-bold leading-tight text-red-600 sm:text-4xl lg:text-5xl">Error 404 - Debug Mode</h1>'
                    . '<pre class="mb-5 text-left bg-gray-100 dark:bg-gray-800 p-4 rounded-lg overflow-auto text-sm">'
                    . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8')
                    . '</pre>'
                    . '<div class="flex justify-center mt-8">'
                    . '<a href="#" data-history-back class="px-8 py-3 bg-gray-600 text-white rounded-xl font-bold hover:bg-gray-700 transition-all cursor-pointer">'
                    . 'Volver</a>'
                    . '</div></div></div>';

                return Response::json([
                    'type' => 'html',
                    'content' => $errorHtml,
                    'scripts' => [],
                    'styles' => [],
                    'urlActual' => $request->getUrl(),
                ], 404);
            }

            // En modo debug sin SPA, mostrar error detallado tradicional
            if ($config['build']['debug']) {
                Response::jsonError([
                    'success' => 0,
                    'message' => sprintf('%s -> %s::%s', $e, $request->getMethod(), $request->getUrl()),
                ], 404);
            } elseif ($isSPARequest) {
                // Para peticiones SPA en producción, renderizar la página 404 como contenido JSON SPA
                $content404 = $this->render404TemplateForSPA();

                return Response::json([
                    'type' => 'html',
                    'content' => $content404,
                    'scripts' => [],
                    'styles' => [],
                    'urlActual' => $request->getUrl(),
                ], 404);
            } else {
                // Petición regular en producción: renderizar template 404
                try {
                    Output::status(404);
                    $template404Content = $this->render404Template();
                    echo $template404Content;
                    Output::halt();
                } catch (RequestHalted $halted) {
                    // Fin de la petición en pruebas (Output::halt): no es un error de la aplicación
                    throw $halted;
                } catch (Throwable $th) {
                    // Si falla el render, log y mostrar fallback
                    Loggin::error('Error renderizando template 404: ' . $th->getMessage(), 'router');
                    Output::status(404);
                    echo
                        '<!DOCTYPE html><html><head><title>404 - Página no encontrada</title></head><body><h1>404 - Página no encontrada</h1><p>La página que buscas no existe.</p></body></html>';
                    Output::halt();
                }
            }
        } else {
            if ($config['build']['debug']) {
                return Response::jsonError([
                    'success' => 0,
                    'message' => sprintf('%s -> %s::%s', $e, $request->getMethod(), $request->getUrl()),
                ], 404);
            }

            return Response::json([
                'success' => 0,
                'message' => 'Recurso no encontrado',
            ], 404);
        }

        return false;
    }

    /**
     * Determina si debe aplicar rate limiting global basado en la petición.
     */
    private function shouldApplyGlobalRateLimit($request): bool
    {
        $url = $request->getUrl();

        // Aplicar rate limiting global a:
        // - Todas las rutas que comienzan con /api/
        // - Rutas de login y autenticación
        // - Peticiones identificadas como API calls
        return (
            str_contains($url, '/api/')
            || str_ends_with($url, '/login')
            || str_ends_with($url, '/authentication')
            || $request->isApiCall()
        );
    }

    /**
     * Maneja rutas no encontradas, distinguiendo entre 404 (ruta no existe) y 405 (método no permitido).
     */
    private function handleRouteNotFound($request, array $config, string $url, string $method): bool|string
    {
        // Verificar si la ruta existe con otros métodos HTTP
        $allRoutes = array_merge(
            self::$getRoutes,
            self::$postRoutes,
            self::$putRoutes,
            self::$patchRoutes,
            self::$deleteRoutes,
            self::$queryRoutes,
        );

        $routeExists = false;
        $allowedMethods = [];

        // Verificar coincidencia exacta primero
        if (array_key_exists($url, $allRoutes)) {
            $routeExists = true;
        }

        // Si no hay coincidencia exacta, verificar rutas con parámetros
        if (!$routeExists) {
            foreach (array_keys($allRoutes) as $route) {
                if (!str_contains($route, '{')) {
                    continue;
                }

                $routePattern = preg_replace('#{[a-zA-Z0-9_]+}#', '([a-zA-Z0-9_\-]+)', $route);
                if (preg_match(sprintf('#^%s$#', $routePattern), $url)) {
                    $routeExists = true;
                    break;
                }
            }
        }

        // Si la ruta existe, determinar qué métodos están permitidos
        if ($routeExists) {
            foreach (self::METHODS as $httpMethod) {
                $routes = self::routeTable($httpMethod);
                if (array_key_exists($url, $routes)) {
                    $allowedMethods[] = strtoupper($httpMethod);
                } else {
                    // Verificar rutas con parámetros
                    foreach (array_keys($routes) as $route) {
                        if (!str_contains($route, '{')) {
                            continue;
                        }

                        $routePattern = preg_replace('#{[a-zA-Z0-9_]+}#', '([a-zA-Z0-9_\-]+)', $route);
                        if (preg_match(sprintf('#^%s$#', $routePattern), $url)) {
                            $allowedMethods[] = strtoupper($httpMethod);
                            break;
                        }
                    }
                }
            }

            // Si la ruta existe pero el método no está permitido, responder 405
            if ($allowedMethods !== []) {
                Output::status(405);
                Output::header('Allow: ' . implode(', ', $allowedMethods));

                return Response::jsonError([
                    'success' => 0,
                    'message' => 'Method Not Allowed',
                    'allowed_methods' => $allowedMethods,
                ], 405);
            }
        }

        // Si llegamos aquí, la ruta no existe (404)
        return $this->respondNotFound($request, $config);
    }

    /**
     * Renderiza el template 404 sin redirección.
     */
    private function render404Template(): string
    {
        try {
            // Usar Twig para renderizar la página 404
            global $twig;

            return $twig->render('versaWYS/404');
        } catch (RequestHalted $halted) {
            // Fin de la petición en pruebas (Output::halt): no es un error de la aplicación
            throw $halted;
        } catch (Throwable) {
            Loggin::error('No se pudo cargar Twig para la página 404.', 'router', [
                'timestamp' => date('Y-m-d H:i:s'),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            // Fallback si no se puede cargar Twig
            return '<!DOCTYPE html><html><head><title>404 - Página no encontrada</title></head><body><h1>404 - Página no encontrada</h1><p>La página que buscas no existe.</p></body></html>';
        }
    }

    /**
     * Renderiza el contenido del template 404 para respuestas SPA.
     * Devuelve solo el HTML del contenido interno (sin layout) ya que SPA
     * solo necesita el fragmento que se inyecta en #content-general.
     */
    private function render404TemplateForSPA(): string
    {
        global $twig;

        return $twig->render('versaWYS/404Content');
    }
}
