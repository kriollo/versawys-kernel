<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Throwable;
use VersaORM\VersaORMException;

/**
 * Manejador global de errores mejorado para versaWYS
 * Proporciona mejor contexto y debugging para errores de RedBeanPHP y otros.
 */
class ErrorHandler
{
    private static bool $initialized = false;

    /**
     * Inicializa el manejador global de errores.
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        // Handler para excepciones no capturadas
        set_exception_handler([self::class, 'handleException']);

        // Handler para errores fatales
        register_shutdown_function([self::class, 'handleShutdown']);

        // Handler para errores de PHP
        set_error_handler([self::class, 'handleError']);

        self::$initialized = true;
    }

    /**
     * Maneja excepciones no capturadas.
     */
    public static function handleException(Throwable $e): void
    {
        global $debug, $versawys_error_handling_in_progress, $request;

        Profiler::exception($e);

        // Si RedBeanCnn ya está manejando un error, no interferir
        if ($versawys_error_handling_in_progress) {
            return;
        }

        // Protección contra ejecución múltiple
        static $errorHandled = false;
        if ($errorHandled) {
            return;
        }

        $errorHandled = true;
        $isApiCall = $request instanceof Request && $request->isApiCall();

        // FAST-PATH para producción: respuesta inmediata sin procesamiento pesado
        if (!$debug) {
            if ($isApiCall) {
                // API call en producción: JSON simple y rápido
                Response::jsonError([
                    'success' => 0,
                    'message' => 'Error interno del servidor',
                ], 500);
            } elseif (Request::isSPARequestStatic()) {
                // Petición SPA en producción: devolver error como JSON SPA renderizable
                Response::json([
                    'type' => 'html',
                    'content' => '<div class="flex justify-center items-center h-screen"><div class="text-center"><h1 class="text-2xl font-bold text-red-600 mb-4">Error interno del servidor</h1><p class="text-gray-600">Por favor, intenta nuevamente más tarde.</p><button onclick="location.reload()" class="mt-4 px-6 py-2 bg-brand text-white rounded-lg">Recargar</button></div></div>',
                    'scripts' => [],
                    'styles' => [],
                ], 500);
            } else {
                // Request regular en producción: mostrar página de error simple sin redirección
                Output::status(500);
                echo
                    '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Error del Servidor</title><style>body{font-family:system-ui,-apple-system,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%)}.container{background:white;padding:3rem;border-radius:1rem;box-shadow:0 20px 60px rgba(0,0,0,0.3);text-align:center;max-width:500px}h1{color:#dc2626;font-size:2rem;margin-bottom:1rem}p{color:#4b5563;margin-bottom:2rem}button{background:#667eea;color:white;border:none;padding:0.75rem 2rem;border-radius:0.5rem;font-size:1rem;cursor:pointer;transition:background 0.2s}button:hover{background:#5a67d8}</style></head><body><div class="container"><h1>Error del Servidor</h1><p>Ha ocurrido un error inesperado. Por favor, intenta nuevamente más tarde.</p><button onclick="window.history.back()">Volver</button></div></body></html>';
                Output::halt();
            }

            return;
        }

        // MODO DEBUG: procesamiento completo (overhead aceptable en desarrollo)
        $trace = $e->getTrace();
        $realOrigin = self::findRealOrigin($trace);

        $errorData = [
            'type' => self::getErrorType($e),
            'message' => $e->getMessage(),
            'real_file' => $realOrigin['file'] ?? $e->getFile(),
            'real_line' => $realOrigin['line'] ?? $e->getLine(),
            'real_function' => $realOrigin['function'] ?? 'Unknown',
            'real_class' => $realOrigin['class'] ?? 'Unknown',
            'user_trace' => self::getUserStackTrace($trace),
            'timestamp' => date('Y-m-d H:i:s'),
            'context' => [
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            ],
        ];

        // Información específica para errores de base de datos
        if ($e instanceof VersaORMException) {
            $errorData['sql_error'] = $e->getErrorDetails() ?? 'Unknown';
            $errorData['type'] = 'Database Error (VersaORM)';
        }

        $errorData['full_trace'] = $e->getTraceAsString();
        $errorData['raw_exception'] = [
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        self::logError($errorData);

        // Un navegador recibe la página con el código; una API o el SPA, el JSON con el fragmento
        if (!$isApiCall && !Request::isSPARequestStatic()) {
            Output::status(500);
            echo self::debugPage($e, $errorData);
            Output::halt();
        }

        $errorData['code'] = self::excerpt((string) $errorData['real_file'], (int) $errorData['real_line']);
        Response::jsonError($errorData, 500);
    }

    /**
     * La página de error del modo debug: mensaje, código alrededor de cada línea de la aplicación en la traza
     * y la petición. Sin Twig (puede ser lo que falló) y todo escapado.
     *
     * @internal
     *
     * @param array<string, mixed> $errorData
     */
    public static function debugPage(Throwable $e, array $errorData = []): string
    {
        $h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $root = (string) getcwd();
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine(), 'function' => '']];
        foreach ($e->getTrace() as $frame) {
            if (!isset($frame['file'], $frame['line'])) {
                continue;
            }

            $frames[] = [
                'file' => $frame['file'],
                'line' => $frame['line'],
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'],
            ];
        }

        $html = '';
        $opened = 0;
        foreach ($frames as $i => $frame) {
            $vendor = str_contains($frame['file'], '/vendor/');
            $code = '';
            // El código solo de los archivos de la aplicación (y del primero, aunque sea del kernel)
            if ($i === 0 || !$vendor) {
                foreach (self::excerpt($frame['file'], $frame['line']) as $n => $line) {
                    $code .= sprintf(
                        '<span class="%s"><b>%d</b>%s</span>',
                        $n === $frame['line'] ? 'hit' : '',
                        $n,
                        $h($line),
                    );
                }
            }

            $html .= sprintf(
                '<details%s class="%s"><summary>%s:%d <i>%s</i></summary>%s</details>',
                $code !== '' && $opened++ < 2 ? ' open' : '', // abiertos: los dos primeros con código
                $vendor ? 'vendor' : '',
                $h(str_replace($root . '/', '', $frame['file'])),
                $frame['line'],
                $h($frame['function']),
                $code !== '' ? '<pre>' . $code . '</pre>' : '',
            );
        }

        $nonce = $h(SecurityUtils::cspNonce());
        $sql = isset($errorData['sql_error'])
            ? '<pre class="sql">'
            . $h(json_encode($errorData['sql_error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            . '</pre>'
            : '';

        return (
            '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . $h($e::class)
            . '</title><style nonce="'
            . $nonce
            . '">'
            . 'body{margin:0;font:14px/1.5 system-ui,sans-serif;background:#f8fafc;color:#0f172a}header{background:#b91c1c;color:#fff;padding:24px}'
            . 'header small{opacity:.8}h1{margin:.25em 0 0;font-size:20px;white-space:pre-wrap;word-break:break-word}main{padding:16px 24px;max-width:1100px}'
            . 'details{background:#fff;border:1px solid #e2e8f0;border-radius:6px;margin:8px 0}details.vendor{opacity:.6}summary{cursor:pointer;padding:8px 12px;font-family:ui-monospace,monospace;word-break:break-all}'
            . 'pre{margin:0;overflow-x:auto;font:13px/1.6 ui-monospace,monospace;border-top:1px solid #e2e8f0}pre span{display:block;padding:0 12px;white-space:pre}'
            . 'pre b{display:inline-block;width:4em;color:#94a3b8;font-weight:400;user-select:none}.hit{background:#fee2e2}.sql{padding:12px;white-space:pre-wrap}'
            . 'dl{display:grid;grid-template-columns:max-content 1fr;gap:4px 16px}dt{color:#64748b}dd{margin:0;word-break:break-all}'
            . '@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e2e8f0}details{background:#1e293b;border-color:#334155}pre{border-color:#334155}.hit{background:#7f1d1d}}'
            . '</style></head><body><header><small>'
            . $h($e::class)
            . ' · modo debug</small><h1>'
            . $h($e->getMessage())
            . '</h1></header><main>'
            . $sql
            . $html
            . '<h2>Petición</h2><dl><dt>Método</dt><dd>'
            . $h($_SERVER['REQUEST_METHOD'] ?? '')
            . '</dd><dt>URL</dt><dd>'
            . $h($_SERVER['REQUEST_URI'] ?? '')
            . '</dd>'
            . '<dt>Id</dt><dd>'
            . $h(Request::id())
            . '</dd></dl></main></body></html>'
        );
    }

    /**
     * Maneja errores fatales en shutdown.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], strict: true)) {
            $errorData = [
                'type' => 'Fatal Error',
                'message' => $error['message'],
                'file' => str_replace(getcwd(), '', $error['file']),
                'line' => $error['line'],
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            self::logError($errorData);

            // Solo mostrar error detallado en modo debug
            global $config;
            if (!isset($config['build']['debug']) || !$config['build']['debug']) {
                $errorData = ['type' => 'Fatal Error', 'message' => 'Internal Server Error'];
            }

            Response::jsonError($errorData, 500);
        }
    }

    /**
     * Maneja errores de PHP.
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // No procesar errores suprimidos con @
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $errorData = [
            'type' => self::getSeverityName($severity),
            'message' => $message,
            'file' => str_replace(getcwd(), '', $file),
            'line' => $line,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // Solo loggear errores importantes
        if (in_array($severity, [E_ERROR, E_WARNING, E_PARSE, E_NOTICE], strict: true)) {
            self::logError($errorData);
        }

        // Para errores críticos, detener ejecución
        if (in_array($severity, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], strict: true)) {
            Response::jsonError($errorData, 500);
        }

        return true;
    }

    /**
     * Las líneas alrededor de `$line`: número => código.
     *
     * @return array<int, string>
     */
    private static function excerpt(string $file, int $line, int $around = 7): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $start = max(1, $line - $around);
        $out = [];
        for ($n = $start; $n <= min(count($lines), $line + $around); $n++) {
            $out[$n] = $lines[$n - 1];
        }

        return $out;
    }

    /**
     * Encuentra el origen real del error en el código del usuario.
     */
    private static function findRealOrigin(array $trace): array
    {
        $skipFiles = [
            '/vendor/',
            '/RedBeanPHP/',
            'RedBeanCnn.php',
            'ErrorHandler.php',
            'versaTwig.php',
            'Response.php',
            '/twig/',
            '/cache/',
            '/compiled/',
        ];

        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            $skip = false;
            foreach ($skipFiles as $skipFile) {
                if (!str_contains($frame['file'], $skipFile)) {
                    continue;
                }

                $skip = true;
                break;
            }

            if (!$skip) {
                return [
                    'file' => str_replace(getcwd(), '', $frame['file']),
                    'line' => $frame['line'] ?? 'Unknown',
                    'function' => $frame['function'] ?? 'Unknown',
                    'class' => $frame['class'] ?? null,
                ];
            }
        }

        return [];
    }

    /**
     * Genera un stack trace centrado en el código del usuario.
     */
    private static function getUserStackTrace(array $trace): array
    {
        $userTrace = [];
        $skipFiles = ['/vendor/', '/RedBeanPHP/', '/twig/', '/cache/', '/compiled/'];

        foreach ($trace as $frame) {
            if (!isset($frame['file'])) {
                continue;
            }

            $skip = false;
            foreach ($skipFiles as $skipFile) {
                if (!str_contains($frame['file'], $skipFile)) {
                    continue;
                }

                $skip = true;
                break;
            }

            if (!$skip) {
                $userTrace[] = [
                    'file' => str_replace(getcwd(), '', $frame['file']),
                    'line' => $frame['line'] ?? 'Unknown',
                    'function' => $frame['function'] ?? 'Unknown',
                    'class' => $frame['class'] ?? null,
                ];
            }
        }

        return $userTrace;
    }

    /**
     * Determina el tipo de error basado en la excepción.
     */
    private static function getErrorType(Throwable $e): string
    {
        if ($e instanceof VersaORMException) {
            return 'Database Error (VersaORM)';
        }

        $className = $e::class;
        $parts = explode('\\', $className);

        return end($parts);
    }

    /**
     * Convierte el nivel de severidad a nombre legible.
     */
    private static function getSeverityName(int $severity): string
    {
        $severities = [
            E_ERROR => 'Fatal Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
        ];

        return $severities[$severity] ?? 'Unknown Error';
    }

    /**
     * Registra errores en archivo de log.
     */
    private static function logError(array $errorData): void
    {
        Loggin::error('An error occurred: ' . $errorData['message'], 'ErrorHandler', $errorData);
    }
}
