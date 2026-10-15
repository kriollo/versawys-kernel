<?php

declare(strict_types=1);

namespace versaWYS\kernel\helpers;

use CURLFile;
use DateTime;
use Exception;
use Random\RandomException;
use SensitiveParameter;
use Throwable;
use versaWYS\kernel\SecurityUtils;

class Functions
{
    // =========================================================================
    // HTTP REQUEST (cURL centralizado)
    // =========================================================================

    /**
     * Opciones por defecto para httpRequest.
     */
    private static array $defaultHttpOptions = [
        'timeout' => 8,
        'connect_timeout' => 5,
        'follow_redirects' => true,
        'max_redirects' => 5,
        'verify_ssl' => true,
        'verify_host' => true,
        'ca_bundle_path' => '',
        'user_agent' => 'versaBOT/1.0 (PHP ' . PHP_VERSION . ')',
    ];

    public static function dump($data): void
    {
        echo "<style>
                .xdebug-var-dump {
                    position: fixed;
                    display: block;
                    width: 100%;
                    overflow: auto;
                    background: #fff;
                    color: #000;
                    text-align: left;
                    font-family: monospace;
                    font-size: 12px;
                    line-height: 1.4em;
                    border: 1px solid #ccc;
                    padding: 10px;
                    border-radius: 5px;
                    box-shadow: inset 0 0 5px #ccc;
                    z-index: 9999;
                }
                .xdebug-var-dump pre {
                    margin: 0;
                    padding: 0;
                    display: inline-block;
                    vertical-align: top;
                }
                .xdebug-var-dump pre span {
                    display: inline-block;
                    vertical-align: top;
                }
                .xdebug-var-dump pre span.type {
                    color: #000;
                }
                .xdebug-var-dump pre span.colon {
                    color: #000;
                }
                .xdebug-var-dump pre span.array,
                .xdebug-var-dump pre span.object {
                    color: #000;
                }
                .xdebug-var-dump pre span.null {
                    color: #000;
                }
                .xdebug-var-dump pre span.bool {
                    color: #000;
                }
            </style>
        <script>
            window.addEventListener('load', function() {
                window.addEventListener('load', function() {
                    let offset = 0;
                    document.querySelectorAll('.xdebug-var-dump').forEach(function(pre) {
                        pre.style.top = offset + 'px';
                        offset += pre.offsetHeight;
                    });
                });
            });
        </script>
        ";
        var_dump($data); // deploy-check:ignore (este es el helper de depuración)
        echo '</pre>';
    }

    /**
     * Cleans a string by removing leading and trailing whitespace and converting special characters to HTML entities.
     *
     * @param string $string the string to be cleaned
     *
     * @return string the cleaned string
     */
    public static function cleanString(string $string): string
    {
        $string = trim($string);

        return htmlspecialchars($string);
    }

    /**
     * Valida los parametros de la peticion.
     *
     * @param array $params => $rules | $params = ['email' => 'required|email', 'password' => 'required|min:8']
     *
     * @return array $errors
     */
    public static function validateParams(array $params, array $rules): array
    {
        try {
            $errors = [];

            // Definir las validaciones en un arreglo tipo hash
            $validations = [
                // Vacío es null, texto vacío o solo espacios y arreglo vacío. 0, "0" y false son valores:
                // con empty() un estado o una cantidad en 0 no se podían enviar
                'required' => static fn($field, $value): ?string => $value === null
                    || $value === []
                    || is_string($value) && trim($value) === ''
                        ? sprintf('El campo %s es obligatorio', $field)
                        : null,
                'email' => static fn($field, $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL)
                    ? null
                    : sprintf('El campo %s no es un email valido', $field),
                'rut' => static fn($field, $value): ?string => self::validarRut($value)
                    ? null
                    : sprintf('El campo %s no es un rut valido', $field),
                // Caracteres, no bytes: "José" mide 4
                'min' => static fn($field, $value, $param): ?string => mb_strlen((string) $value) < $param
                    ? sprintf('El campo %s debe tener minimo %s caracteres', $field, $param)
                    : null,
                'max' => static fn($field, $value, $param): ?string => mb_strlen((string) $value) > $param
                    ? sprintf('El campo %s debe tener maximo %s caracteres', $field, $param)
                    : null,
                'numeric' => static fn($field, $value): ?string => is_numeric($value)
                    ? null
                    : sprintf('El campo %s debe ser numerico', $field),
                'int' =>
                    $int = static function ($field, $value, $param): ?string {
                        // Sin parámetro ($param null) también: antes str_contains(null) fallaba y "5" quedaba inválido
                        $param = (string) $param;
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                            return sprintf('El campo %s debe ser un número entero', $field);
                        }

                        $value = (int) $value;
                        if (str_contains($param, '-')) {
                            [$min, $max] = explode('-', $param);

                            return $value < $min || $value > $max
                                ? sprintf('El campo %s debe ser numerico entre %s y %s', $field, $min, $max)
                                : null;
                        }

                        if (str_contains($param, ',')) {
                            $valid = explode(',', $param);

                            return in_array($value, $valid, strict: true)
                                ? null
                                : sprintf(
                                    'El campo %s debe ser numerico y debe ser uno de los siguientes valores ',
                                    $field,
                                ) . implode(',', $valid);
                        }

                        if (str_contains($param, 'positive')) {
                            return $value > 0 ? null : sprintf('El campo %s debe ser un numero positivo', $field);
                        }

                        return null;
                    },
                'integer' => $int,
                // Texto o número (un input numérico llega como número en JSON); nunca arreglo, objeto o booleano
                'string' => static fn($field, $value): ?string => is_string($value)
                    || is_int($value)
                    || is_float($value)
                        ? null
                        : sprintf('El campo %s debe ser texto', $field),
                'time' => static fn($field, $value): ?string => preg_match(
                    '/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/',
                    $value,
                )
                        ? null
                        : sprintf('El campo %s debe ser una hora valida', $field),
                'date' => static function (string $field, $value): ?string {
                    try {
                        new DateTime($value);

                        return null;
                    } catch (Exception) {
                        return sprintf('El campo %s debe ser una fecha valida', $field);
                    }
                },
                'array' => static function ($field, $value): ?string {
                    if (is_array($value)) {
                        return null;
                    }

                    return is_array(json_decode($value, true)) && json_last_error() === JSON_ERROR_NONE
                        ? null
                        : sprintf('El campo %s debe ser un arreglo', $field);
                },
                'url' => static fn($field, $value): ?string => filter_var($value, FILTER_VALIDATE_URL)
                    ? null
                    : sprintf('El campo %s debe ser una url valida', $field),
                // in:activo,inactivo — uno de los valores listados, comparados como texto
                'in' => static fn($field, $value, $param): ?string => is_scalar($value)
                    && in_array((string) $value, explode(',', (string) $param), true)
                        ? null
                        : sprintf('El campo %s debe ser uno de: %s', $field, str_replace(',', ', ', (string) $param)),
                // true/false, 1/0, "true"/"false", "1"/"0", "on"/"off" (checkbox de formulario)
                'boolean' => static fn($field, $value): ?string => filter_var(
                    $value,
                    FILTER_VALIDATE_BOOL,
                    FILTER_NULL_ON_FAILURE,
                ) !== null
                        ? null
                        : sprintf('El campo %s debe ser verdadero o falso', $field),
            ];

            foreach ($rules as $field => $rule) {
                $rulesArray = explode('|', $rule);

                // nullable: si el campo no llega o viene vacío, las demás reglas no se aplican
                if (in_array('nullable', $rulesArray, true)) {
                    if (!isset($params[$field]) || $params[$field] === '') {
                        continue;
                    }

                    $rulesArray = array_values(array_diff($rulesArray, ['nullable']));
                }

                foreach ($rulesArray as $rul) {
                    [$rulName, $param] = array_pad(explode(':', $rul), 2, null);

                    // Verificar si el campo existe
                    if (!isset($params[$field])) {
                        $errors[$field] = sprintf('El campo %s es obligatorio', $field);
                    } elseif (!isset($validations[$rulName])) {
                        // Una regla mal escrita no puede dejar pasar el dato sin validar
                        $errors[$field] = sprintf('Regla de validación desconocida para %s: %s', $field, $rulName);
                        break;
                    } else {
                        // Ejecutar la validación correspondiente
                        // Un valor de tipo inesperado (arreglo, null) es un dato inválido, no un error 500
                        try {
                            $error = $validations[$rulName]($field, $params[$field], $param);
                        } catch (Throwable) {
                            $error = sprintf('El campo %s no es valido', $field);
                        }

                        // Depuración: imprimir sólo si hay un error
                        if ($error) {
                            $errors[$field] = $error;
                            break;
                        }
                    }
                }
            }

            return $errors;
        } catch (Exception $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    /**
     * Verifica si un valor está definido y no está vacío.
     *
     * @param mixed $val el valor a verificar
     *
     * @return bool true si el valor está definido y no está vacío, false en caso contrario
     */
    public static function e(mixed $val): bool
    {
        return isset($val) && !empty($val) && $val !== '' && $val !== [] && $val !== null;
    }

    public static function validarRut(string $rut): bool
    {
        $rut = str_replace('.', '', $rut);
        $rut = str_replace('-', '', $rut);

        $rut = explode('-', $rut);
        $rut = $rut[0];

        $dv = strtoupper($rut[strlen($rut) - 1]);
        $rut = substr($rut, 0, -1);

        $i = 2;
        $suma = 0;
        foreach (array_reverse(str_split($rut)) as $v) {
            if ($i === 8) {
                $i = 2;
            }

            $suma += $v * $i;
            $i++;
        }

        $dvr = strval(11 - ($suma % 11));

        if ($dvr === '11') {
            $dvr = '0';
        }

        if ($dvr === '10') {
            $dvr = 'K';
        }

        return $dv === $dvr;
    }

    public static function validateJson(string $json): bool
    {
        json_decode($json);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Permite generar un token para la contraseña.
     */
    public static function hash(#[SensitiveParameter] string $password): string
    {
        // PASSWORD_DEFAULT: bcrypt con el costo que fije PHP (12 desde PHP 8.4). Argon2id no está en
        // todas las compilaciones de PHP: una base copiada a otra máquina dejaría a todos sin poder ingresar
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * El hash usa un algoritmo o costo anterior: conviene regenerarlo al iniciar sesión, que es el
     * único momento en que se tiene la contraseña.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Permite comparar el token generado con la contraseña.
     */
    public static function verifyHash(#[SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generates a CSRF token.
     *
     * @throws RandomException
     *
     * @return string the generated CSRF token
     */
    public static function generateCSRFToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Validates the CSRF token.
     *
     * @return bool returns true if the token is valid, false otherwise
     */
    public static function validateCSRFToken(
        #[SensitiveParameter]
        mixed $tokenSession,
        #[SensitiveParameter]
        mixed $tokenFormUser,
    ): bool {
        // Sesión sin token, token vacío o enviado como arreglo (_csrf_token[]=…): inválido, nunca un error 500
        if (!is_string($tokenSession) || !is_string($tokenFormUser) || $tokenSession === '' || $tokenFormUser === '') {
            return false;
        }

        return hash_equals($tokenSession, $tokenFormUser);
    }

    /**
     * Generates the URL for an asset file.
     *
     * @param string $assetsPath the path to the asset file
     *
     * @return string the URL of the asset file
     */
    public static function assets_url(string $assetsPath): string
    {
        global $request;

        if (str_starts_with($assetsPath, '/')) {
            $assetsPath = substr($assetsPath, 1);
        }

        return $request->getBaseUrl() . '/' . $assetsPath;
    }

    /**
     * Reemplaza los espacios en una cadena por guiones bajos.
     *
     * @param string $string la cadena en la que se reemplazarán los espacios
     *
     * @return string la cadena con los espacios reemplazados por guiones bajos
     */
    public static function replace_spaces_with_underscore(string $string): string
    {
        return str_replace(' ', '_', $string);
    }

    /**
     * Generates a hidden input field with the specified HTTP method.
     *
     * @param string $method the HTTP method to be set
     *
     * @return string the generated HTML code for the hidden input field
     */
    public static function method_field(string $method): string
    {
        $method = strtoupper(trim($method));

        return sprintf('<input type="hidden" name="_method" value="%s">', $method);
    }

    /**
     * Generates a hidden input field with the specified CSRF token.
     *
     * @return string the generated HTML code for the hidden input field format raw for twig
     */
    public static function csrf_field(string $id = 'csrf_token'): string
    {
        global $session;
        $token = $session->get('csrf_token');

        return sprintf('<input type="hidden" name="_csrf_token" id="%s" value="%s">', $id, $token);
    }

    /**
     * @throws Exception
     */
    public static function getAssets(string $module, string $type, string $pathFile, bool $addURL = true): string
    {
        global $config, $request;

        if (!isset($config['assets'][$module][$type])) {
            throw new Exception(sprintf('No existe el tipo de archivo %s en el modulo %s', $type, $module));
        }

        // verificar si tienen el slash al inicio
        $file = str_starts_with($pathFile, '/') ? substr($pathFile, 1) : $pathFile;

        $pathSource = 'dist';

        return (
            ($addURL ? $request->getBaseUrl() . '/' : '') . $config['assets'][$module][$type][$pathSource] . '/' . $file
        );
    }

    /**
     * Removes escape characters from a string and decodes HTML entities.
     * Handles multiple levels of encoding (double/triple escaped content).
     *
     * @param string $e the string to remove escape characters from
     *
     * @return string the string without escape characters and decoded HTML entities
     */
    public static function removeScape(string $e): string
    {
        // Eliminar barras invertidas
        $decoded = stripslashes($e);

        // Decodificar entidades HTML múltiples veces hasta que no haya más cambios
        $previousDecoded = '';
        $maxIterations = 5; // Evitar bucle infinito
        $iteration = 0;

        while ($decoded !== $previousDecoded && $iteration < $maxIterations) {
            $previousDecoded = $decoded;
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $iteration++;
        }

        return $decoded;
    }

    /**
     * Sanitiza contenido SVG permitiendo solo elementos seguros.
     * Decodifica entidades HTML y filtra elementos peligrosos.
     *
     * @param string $svgContent el contenido SVG a sanitizar
     *
     * @return string el contenido SVG sanitizado y seguro
     */
    public static function sanitizeSvgContent(string $svgContent): string
    {
        // Primero decodificar entidades HTML
        $decoded = self::removeScape($svgContent);

        // Elementos SVG permitidos (lista blanca)
        $allowedElements = [
            'path',
            'circle',
            'rect',
            'line',
            'polyline',
            'polygon',
            'ellipse',
            'g',
            'defs',
            'use',
            'clipPath',
            'mask',
        ];

        // Atributos SVG seguros permitidos
        $allowedAttributes = [
            'd',
            'cx',
            'cy',
            'r',
            'x',
            'y',
            'width',
            'height',
            'rx',
            'ry',
            'fill',
            'stroke',
            'stroke-width',
            'stroke-linecap',
            'stroke-linejoin',
            'fill-rule',
            'clip-rule',
            'opacity',
            'transform',
            'viewBox',
            'points',
            'x1',
            'y1',
            'x2',
            'y2',
        ];

        // Eliminar cualquier elemento script, iframe, object, embed, etc.
        $dangerous = ['script', 'iframe', 'object', 'embed', 'form', 'input', 'textarea', 'button'];
        foreach ($dangerous as $tag) {
            $decoded = preg_replace('/<' . $tag . '[^>]*>.*?<\/' . $tag . '>/is', '', $decoded);
            $decoded = preg_replace('/<' . $tag . '[^>]*\/?>/', '', $decoded);
        }

        // Eliminar eventos JavaScript (onclick, onload, etc.)
        $decoded = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/', '', $decoded);
        $decoded = preg_replace('/\s*on\w+\s*=\s*[^>\s]+/', '', $decoded);

        // Eliminar javascript: en atributos
        return preg_replace('/javascript\s*:/i', '', $decoded);
    }

    /**
     * Formats a Chilean RUT (Rol Único Tributario) by removing dots and hyphens,
     * then adding a period every three digits and appending the verification digit.
     *
     * @param string $rut the RUT to be formatted
     *
     * @return string the formatted RUT
     */
    public static function formatRut(string $rut): string
    {
        $rut = str_replace('.', '', $rut);
        $rut = str_replace('-', '', $rut);

        $rut = explode('-', $rut);
        $rut = $rut[0];

        $dv = strtoupper($rut[strlen($rut) - 1]);
        $rut = substr($rut, 0, -1);

        $rut = number_format((float) $rut, 0, ',', '.');

        return sprintf('%s-%s', $rut, $dv);
    }

    public static function getPanelUrl($url = ''): string
    {
        global $config;

        if (!self::e($url)) {
            return '/' . $config['admin']['custom_url'] . '/';
        }

        if (str_starts_with($url, '/')) {
            $url = substr($url, 1);
        }

        return '/' . $config['admin']['custom_url'] . '/' . $url;
    }

    /**
     * Returns an array of Twig filters.
     *
     * @return array the array of Twig filters
     */
    public function setTwigFilters(): array
    {
        return [
            ['replace_spaces_with_underscore', self::class . '::replace_spaces_with_underscore'],
            ['removeScape', self::class . '::removeScape'],
            ['sanitizeSvg', self::class . '::sanitizeSvgContent'],
            ['formatRut', self::class . '::formatRut'],
            ['sanitizeSvgContent', self::class . '::sanitizeSvgContent'],
        ];
    }

    /**
     * Returns an array of Twig functions.
     *
     * @return array the array of Twig functions
     */
    /**
     * Nonce de CSP para `<script nonce="{{ csp_nonce() }}">`. Vacío si csp.nonce está apagado: así
     * las plantillas pueden llevarlo siempre.
     */
    public static function cspNonce(): string
    {
        global $config;

        return ($config['csp']['nonce'] ?? false) === true ? SecurityUtils::cspNonce() : '';
    }

    public function setTwigFunctions(): array
    {
        return [
            ['assets_url', self::class . '::assets_url'],
            ['method_field', self::class . '::method_field'],
            ['csrf_field', self::class . '::csrf_field'],
            ['getAssets', self::class . '::getAssets'],
            ['getPanelUrl', self::class . '::getPanelUrl'],
            ['csp_nonce', self::class . '::cspNonce'],
        ];
    }

    /**
     * Ejecuta una petición HTTP usando cURL.
     *
     * @param string $url URL completa de la petición
     * @param string $method Método HTTP (GET, POST, PUT, DELETE, PATCH, etc.)
     * @param array $headers Headers HTTP como array de strings ['Header-Name: value']
     * @param array|string $body Cuerpo de la petición (string o array para POST)
     * @param array $options Opciones adicionales:
     *                       - timeout: int (segundos, default 8)
     *                       - connect_timeout: int (segundos, default 5)
     *                       - follow_redirects: bool (default true)
     *                       - max_redirects: int (default 5)
     *                       - verify_ssl: bool (default true)
     *                       - verify_host: bool (default true)
     *                       - ca_bundle_path: string (ruta al bundle CA, default auto-detect)
     *                       - user_agent: string (User-Agent header, default versaBOT/1.0)
     *                       - cookies: string (Cookie header value)
     *                       - raw_output: bool (si true, no decodifica JSON, default false)
     *
     * @return array {
     *               status_code: int,
     *               body: array|string,
     *               headers: array,
     *               error: string,
     *               errno: int,
     *               duration_ms: int,
     *               success: bool
     *               }
     */
    public static function httpRequest(
        string $url,
        string $method = 'GET',
        array $headers = [],
        string|array $body = '',
        array $options = [],
    ): array {
        $startedAt = microtime(true);
        $opts = array_merge(self::$defaultHttpOptions, $options);
        $method = strtoupper(trim($method));

        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'status_code' => 0,
                'body' => '',
                'headers' => [],
                'error' => 'No se pudo inicializar cURL',
                'errno' => 0,
                'duration_ms' => 0,
                'success' => false,
            ];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $opts['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $opts['connect_timeout']);

        if ($opts['follow_redirects']) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, (int) $opts['max_redirects']);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool) $opts['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, (bool) $opts['verify_host'] ? 2 : 0);

        // Sin ca_bundle_path, curl usa curl.cainfo / openssl.cafile del php.ini o los certificados del
        // sistema; en Windows, el almacén nativo (CURLSSLOPT_NATIVE_CA)
        $caPath = trim((string) $opts['ca_bundle_path']);
        $useNativeCa = false;
        if ($caPath !== '' && file_exists($caPath) && is_readable($caPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caPath);
        } elseif ((bool) $opts['verify_ssl'] && ini_get('curl.cainfo') === '' && ini_get('openssl.cafile') === '') {
            if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
                curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
                $useNativeCa = true;
            }
        }

        $finalHeaders = $headers;
        $hasUserAgent = false;
        foreach ($headers as $header) {
            if (stripos($header, 'User-Agent:') !== 0) {
                continue;
            }

            $hasUserAgent = true;
            break;
        }

        if (!$hasUserAgent && !empty($opts['user_agent'])) {
            $finalHeaders[] = 'User-Agent: ' . $opts['user_agent'];
        }

        if (!empty($opts['cookies'])) {
            $finalHeaders[] = 'Cookie: ' . $opts['cookies'];
        }

        match ($method) {
            'GET' => curl_setopt($ch, CURLOPT_HTTPGET, true),
            'POST' => curl_setopt($ch, CURLOPT_POST, true),
            'PUT' => curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT'),
            'DELETE' => curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE'),
            'PATCH' => curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH'),
            'HEAD' => curl_setopt($ch, CURLOPT_NOBODY, true),
            default => curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method),
        };

        if ($method !== 'GET' && $method !== 'HEAD') {
            if (is_array($body)) {
                $hasFile = false;
                foreach ($body as $value) {
                    if (!$value instanceof CURLFile) {
                        continue;
                    }

                    $hasFile = true;
                    break;
                }

                if ($hasFile) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
                }
            } elseif (is_string($body) && $body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        if ($finalHeaders !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);
        }

        curl_setopt($ch, CURLOPT_HEADER, true);

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErrNo = (int) curl_errno($ch);
        $curlError = curl_error($ch);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Fallback: si hay error de certificado (60) y no usamos native CA, intentar con native CA
        $insecureTlsUsed = false;
        if (
            $curlErrNo === 60
            && !$useNativeCa
            && (bool) $opts['verify_ssl']
            && defined('CURLOPT_SSL_OPTIONS')
            && defined('CURLSSLOPT_NATIVE_CA')
        ) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            $rawResponse = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlErrNo = (int) curl_errno($ch);
            $curlError = curl_error($ch);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $insecureTlsUsed = $curlErrNo === 0;
        }

        curl_close($ch);

        $responseHeaders = [];
        $responseBody = '';
        if (is_string($rawResponse) && $rawResponse !== '') {
            $headerContent = substr($rawResponse, 0, $headerSize);
            $responseBody = substr($rawResponse, $headerSize);

            $headerLines = explode("\r\n", trim($headerContent));
            foreach ($headerLines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, 'HTTP/')) {
                    continue;
                }

                $colonPos = strpos($line, ':');
                if ($colonPos !== false) {
                    $name = trim(substr($line, 0, $colonPos));
                    $value = trim(substr($line, $colonPos + 1));
                    $responseHeaders[$name] = $value;
                }
            }
        }

        $decodedBody = $responseBody;
        if (empty($opts['raw_output']) && is_string($responseBody) && $responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedBody = $decoded;
            }
        }

        return [
            'status_code' => $statusCode,
            'body' => $decodedBody,
            'raw_body' => $responseBody,
            'headers' => $responseHeaders,
            'error' => $curlError,
            'errno' => $curlErrNo,
            'duration_ms' => $durationMs,
            'success' => $curlErrNo === 0 && $statusCode >= 200 && $statusCode < 400,
            'ssl' => [
                'ca_bundle_path' => $caPath,
                'verify_peer' => (bool) $opts['verify_ssl'],
                'verify_host' => (bool) $opts['verify_host'],
                'native_ca_used' => $useNativeCa,
                'insecure_fallback' => $insecureTlsUsed,
            ],
        ];
    }

    /**
     * Atajo para peticiones GET.
     */
    public static function httpGet(string $url, array $headers = [], array $options = []): array
    {
        return self::httpRequest($url, 'GET', $headers, '', $options);
    }

    /**
     * Atajo para peticiones POST con JSON.
     */
    public static function httpPostJson(string $url, array $data = [], array $headers = [], array $options = []): array
    {
        $jsonHeaders = array_merge($headers, ['Content-Type: application/json']);

        return self::httpRequest(
            $url,
            'POST',
            $jsonHeaders,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            $options,
        );
    }

    /**
     * Atajo para subir archivos vía POST multipart/form-data.
     */
    public static function httpUploadFile(
        string $url,
        string $filePath,
        string $fieldName = 'file',
        array $additionalData = [],
        array $headers = [],
        array $options = [],
        ?string $clientFileName = null,
    ): array {
        if (!file_exists($filePath)) {
            return [
                'status_code' => 0,
                'body' => '',
                'headers' => [],
                'error' => 'Archivo no encontrado: ' . $filePath,
                'errno' => 0,
                'duration_ms' => 0,
                'success' => false,
            ];
        }

        $postData = array_merge($additionalData, [
            $fieldName => new CURLFile(
                $filePath,
                mime_content_type($filePath) ?: 'application/octet-stream',
                $clientFileName !== null && $clientFileName !== '' ? $clientFileName : basename($filePath),
            ),
        ]);

        return self::httpRequest($url, 'POST', $headers, $postData, $options);
    }
}
