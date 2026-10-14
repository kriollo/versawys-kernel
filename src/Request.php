<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Exception;
use RuntimeException;
use Throwable;
use versaWYS\kernel\helpers\Functions;

class Request
{
    protected mixed $contentType;

    protected string $method;

    protected mixed $url;

    protected array $params;

    protected array $files;

    protected mixed $ip;

    protected mixed $accept;

    protected mixed $rawBody;

    protected mixed $rawBodyLength;

    public function __construct()
    {
        try {
            $this->contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            $this->method = strtoupper($_SERVER['REQUEST_METHOD']) ?? 'GET';
            $this->url = $_SERVER['REQUEST_URI'] ?? '/';
            $this->params = $_REQUEST;
            $this->files = $_FILES;
            $this->ip = self::clientIp();
            $this->accept = $_SERVER['HTTP_ACCEPT'] ?? '*/*';
            $this->prepareFiles();
            $this->prepareParams();

            $this->rawBody = file_get_contents('php://input') ?? null;
            $this->rawBodyLength = strlen($this->rawBody) ?? 0;

            // Solo un POST puede cambiar de método (nunca convertir un GET en escritura): por el campo
            // _method de un formulario o por X-HTTP-Method-Override, que además permite usar QUERY
            // donde el servidor no lo acepta (php -S responde 501)
            $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $this->params['_method'] ?? null;
            if ($this->method === 'POST' && is_string($override) && $override !== '') {
                $this->method = strtoupper($override);
            }
        } catch (Throwable $throwable) {
            $error_message = 'Error al inicializar la solicitud: ' . $throwable->getMessage();
            Loggin::error($error_message, 'request', [
                'exception' => $throwable,
            ]);

            // Handle error according to debug mode
            global $config;
            if (isset($config['build']['debug']) && $config['build']['debug']) {
                // In debug mode, use ErrorHandler to show detailed error
                if (class_exists('versaWYS\\kernel\\ErrorHandler')) {
                    $errorHandler = new ErrorHandler();
                    $errorHandler->handleException(new RuntimeException($error_message));
                    exit();
                }

                // Fallback if ErrorHandler is not available yet
                Loggin::error('ErrorHandler no disponible: ' . $error_message, 'request', [$throwable]);

                throw new Exception($error_message, $throwable->getCode(), $throwable);
            }

            // In production mode, show clean error
            Loggin::error('Request initialization error in production: ' . $error_message, 'request');

            // Use our static method to determine if it's an API call
            $isApi = Request::isApiRequest();

            if ($isApi) {
                Response::jsonError([
                    'message' => 'Error interno del servidor',
                    'code' => 500,
                ], 500);
                exit();
            }

            http_response_code(500);
            echo '<h1>Error del sistema</h1><p>Error de inicialización. Por favor, contacte al administrador.</p>';
            exit();
        }
    }

    public function procesaFormData($params): array
    {
        $boundary = '--' . explode('boundary=', $_SERVER['CONTENT_TYPE'])[1];
        $parts = explode($boundary, $params);
        $data = [];
        $files = [];

        foreach ($parts as $part) {
            if (!($part !== '' && $part !== '0' && str_contains($part, 'name='))) {
                continue;
            }

            preg_match('/name="(.*?)"/', $part, $matches);
            $fieldName = $matches[1];

            if (strpos($part, 'filename=') && $_FILES === []) {
                $file = $this->extractFilesFormData($part);
                $files[$fieldName] = $file;

                continue;
            }

            preg_match('/\r\n\r\n(.*?)\r\n/', $part, $matches);

            $fieldValue = $matches[1];
            $data[$fieldName] = $fieldValue;
        }

        if ($files !== [] && $_FILES === []) {
            $this->files = filter_var_array($files, FILTER_SANITIZE_SPECIAL_CHARS);
        }

        return $data;
    }

    public function setParam(string $fieldName, mixed $value): void
    {
        $this->params[$fieldName] = $value;
    }

    /**
     * Obtiene el valor de un campo de entrada de la solicitud.
     *
     * @param string $fieldName el nombre del campo de entrada
     *
     * @return mixed el valor del campo de entrada o null si no está presente
     */
    public function get(string $fieldName, mixed $default = null): mixed
    {
        return $this->params[$fieldName] ?? $default;
    }

    /**
     * Returns the file with the given file name from the request.
     *
     * @param string $fileName the name of the file to retrieve
     *
     * @throws Exception
     *
     * @return File|null the file object if it exists, or null if it doesn't
     */
    public function file(string $fileName): ?File
    {
        if (isset($this->files[$fileName])) {
            return new File($this->files[$fileName]);
        }

        return null;
    }

    /**
     * Parámetros de la solicitud tal como los envió el cliente. El escape se hace al mostrar
     * (Twig y Vue escapan por defecto) y el SQL va siempre con parámetros.
     *
     * @return array los parámetros de la solicitud
     */
    public function getAllParams(): array
    {
        return $this->params;
    }

    /**
     * Igual que getAllParams(). Desde 3.0 la entrada ya no se reescribe; se mantiene porque los
     * proyectos la usan donde necesitaban el valor sin sanitizar.
     *
     * @return array Los parámetros originales
     */
    public function getRawParams(): array
    {
        return $this->params;
    }

    /**
     * Obtiene todos los archivos adjuntos de la solicitud.
     *
     * @throws Exception
     *
     * @return array un array de objetos File que representan los archivos adjuntos
     */
    public function getAllFiles($fileName): array
    {
        $files = [];

        if (!isset($this->files[$fileName]) || !isset($this->files[$fileName]['name'])) {
            return []; // No hay archivos para este nombre de campo
        }

        // Si 'name' no es un array, es un solo archivo.
        if (!is_array($this->files[$fileName]['name'])) {
            // Verificar si es un archivo válido antes de procesarlo
            if (isset($this->files[$fileName]['tmp_name']) && $this->files[$fileName]['tmp_name'] !== '') {
                try {
                    return [new File($this->files[$fileName])];
                } catch (Exception) {
                    // Registrar el error o manejarlo según sea necesario
                    return [];
                }
            }

            return [];
        }

        $fileCount = count($this->files[$fileName]['name']);

        for ($i = 0; $i < $fileCount; $i++) {
            $currentFile = [];
            foreach ($this->files[$fileName] as $key => $value) {
                $currentFile[$key] = $value[$i] ?? null;
            }

            // Solo procesar si tmp_name está presente y no está vacío
            if (isset($currentFile['tmp_name']) && $currentFile['tmp_name'] !== '') {
                try {
                    $files[] = new File($currentFile);
                } catch (Exception) {
                    // Registrar el error o manejarlo, opcionalmente continuar con otros archivos
                }
            }
        }

        return $files;
    }

    public function getContentType(): mixed
    {
        return $this->contentType;
    }

    public function getMethod(): string
    {
        return strtoupper($this->method);
    }

    public function getUrl()
    {
        return $this->url;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getClientIp(): string
    {
        return $this->ip;
    }

    public function getRawBody(): mixed
    {
        return $this->rawBody;
    }

    /**
     * Returns the base URL of the current request.
     *
     * @return string the base URL of the current request
     */
    public function getBaseUrl(): string
    {
        global $config;

        // Con build.base_url fijo, los enlaces de los correos no dependen de cabeceras del cliente
        $configured = rtrim((string) ($config['build']['base_url'] ?? ''), '/');
        if ($configured !== '') {
            return $configured;
        }

        $https = in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true);
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Las cabeceras X-Forwarded-* solo valen si las puso un proxy propio: si no, cualquiera
        // podría apuntar el enlace de recuperación de contraseña a su dominio
        if (self::isTrustedProxy((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
            $https = $https || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            $forwardedHost = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
            $host = $forwardedHost !== '' ? $forwardedHost : $host;
        }

        return ($https ? 'https://' : 'http://') . $host;
    }

    /**
     * IP del cliente. X-Forwarded-For solo se cree si la petición llega desde `trusted_proxies`.
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!self::isTrustedProxy($remote)) {
            return $remote;
        }

        // De derecha a izquierda: la primera IP que no es un proxy propio es la del cliente
        $chain = array_map(trim(...), explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        foreach (array_reverse($chain) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false && !self::isTrustedProxy($ip)) {
                return $ip;
            }
        }

        return $remote;
    }

    /**
     * ¿Está la IP en `trusted_proxies`? Acepta IPs sueltas y rangos CIDR (IPv4 e IPv6).
     */
    public static function isTrustedProxy(string $ip): bool
    {
        global $config;

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach ((array) ($config['trusted_proxies'] ?? []) as $range) {
            $parts = explode('/', (string) $range, 2);
            $subnetBin = @inet_pton($parts[0]);
            if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin)) {
                continue;
            }

            $bits = isset($parts[1]) ? (int) $parts[1] : strlen($ipBin) * 8;
            $bytes = intdiv($bits, 8);
            $mask = (0xFF << (8 - $bits % 8)) & 0xFF;
            if (
                substr($ipBin, 0, $bytes) === substr($subnetBin, 0, $bytes)
                && ($bits % 8 === 0 || (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtiene el valor de una cabecera HTTP específica.
     *
     * @param string $headerName el nombre de la cabecera
     *
     * @return string|null el valor de la cabecera o null si no se encuentra
     */
    public function getHeader(string $headerName): ?string
    {
        $headers = getallheaders();
        $headerName = strtolower($headerName);

        foreach ($headers as $key => $headerValue) {
            if (strtolower($key) !== $headerName) {
                continue;
            }

            return $headerValue;
        }

        return null;
    }

    /**
     * Determina si la petición actual es una navegación SPA (Single Page Application).
     * Este es el método centralizado para detectar peticiones SPA en todo el framework.
     * Debe usarse en lugar de verificaciones inline en Router, Response, versaTwig, etc.
     */
    public function isSPARequest(): bool
    {
        return (
            isset($_SERVER['HTTP_X_SPA'])
            || isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'
        );
    }

    /**
     * Versión estática de isSPARequest() para contextos donde no hay instancia de Request.
     * Útil en ErrorHandler, Response y otros contextos estáticos.
     */
    public static function isSPARequestStatic(): bool
    {
        return (
            isset($_SERVER['HTTP_X_SPA'])
            || isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'
        );
    }

    /**
     * Determina en base a los headers recibidos si es una llamada por API REST o navegador.
     */
    public function isApiCall(): bool
    {
        return self::isApiRequest();
    }

    /**
     * ¿La petición en curso espera JSON? Versión estática para quien no tiene un Request a mano
     * (Session, File, versaTwig). Una navegación SPA (X-Spa) no es una llamada API.
     */
    public static function isApiRequest(): bool
    {
        if (isset($_SERVER['HTTP_X_SPA'])) {
            return false;
        }

        if (str_contains(strtolower((string) ($_SERVER['REQUEST_URI'] ?? '')), '/api/')
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
            return true;
        }

        // Cuerpos típicos de API (incluye formularios enviados por fetch)
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        foreach (['application/json', 'application/xml', 'multipart/form-data', 'application/x-www-form-urlencoded'] as $type) {
            if ($contentType !== '' && str_starts_with($contentType, $type)) {
                return true;
            }
        }

        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    protected function prepareParams(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Para GET, aplicar filtrado básico pero no agresivo
            $this->params = $_GET ?? [];
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($this->contentType !== '' && str_starts_with(strtolower($this->contentType), 'application/json')) {
                $this->params = json_decode(file_get_contents('php://input'), true) ?? [];
            } else {
                // Para POST multipart/form-data, no aplicar filtros agresivos
                $this->params = $_POST;
                foreach (array_keys($this->params) as $key) {
                    $this->processJsonValue($key);
                }
            }
        } else {
            $this->processNonPostParams();
        }
    }

    protected function processNonPostParams(): void
    {
        $params = file_get_contents('php://input');

        // str_starts_with: el Content-Type suele llevar "; charset=utf-8"
        if (str_starts_with(strtolower($this->contentType), 'application/json')) {
            $this->params = $params === '' || $params === null || !Functions::validateJson($params)
                ? []
                : json_decode($params, true);
        } elseif (str_contains($this->contentType, 'multipart/form-data')) {
            $this->params = $this->procesaFormData($params);
        } else {
            parse_str($params, $this->params);
        }

        foreach (array_keys($this->params) as $key) {
            $this->processJsonValue($key);
        }
    }

    protected function processJsonValue($key): void
    {
        $value = $this->params[$key];

        // Solo procesar como JSON si necesita decodificación de entidades HTML
        // Para campos como 'data' que ya son JSON válido, mantenerlos como string
        if (
            is_string($value)
            && str_contains($value, '{')
            && (str_contains($value, '&lt;') || str_contains($value, '&gt;') || str_contains($value, '&quot;'))
        ) {
            // Solo decodificar entidades HTML, mantener como JSON string
            $decodedValue = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

            // Verificar que sigue siendo JSON válido después de decodificar
            if (json_decode($decodedValue, true) !== null && json_last_error() === JSON_ERROR_NONE) {
                $this->params[$key] = $decodedValue; // Mantener como string JSON
            }
        }
    }

    protected function prepareFiles(): void
    {
        $this->files = filter_var_array($_FILES, FILTER_SANITIZE_SPECIAL_CHARS);
    }

    private function extractFilesFormData(string $part): array
    {
        $fileName = explode('filename=', $part)[1];
        $fileName = explode("\r\n", $fileName)[0];
        $fileName = str_replace('"', '', $fileName);

        $fileType = explode("\r\n", explode('Content-Type: ', $part)[1])[0];

        $fileTmpContent = explode("\r\n\r\n", $part)[1];
        $fileTmpName = tempnam(sys_get_temp_dir(), 'uploaded_file_');

        file_put_contents($fileTmpName, $fileTmpContent);
        chmod($fileTmpName, 0o600);

        return [
            'name' => $fileName,
            'type' => $fileType,
            'tmp_name' => $fileTmpName,
            'error' => 0, // Puedes ajustar según necesidad
            'size' => strlen($fileTmpContent), // Puedes ajustar según necesidad
            'from' => 'formData',
        ];
    }
}
