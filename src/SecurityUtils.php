<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use SensitiveParameter;

/**
 * Clase de utilidades de seguridad para sanitización y validación de datos.
 */
class SecurityUtils
{
    /** Nonce de la CSP de la petición actual: el mismo en la cabecera y en cada <script>. */
    private static ?string $cspNonce = null;

    /**
     * Sanitiza una URL removiendo caracteres peligrosos para SQL injection
     * de manera inteligente, preservando rutas legítimas.
     *
     * @param string $url URL a sanitizar
     *
     * @return string URL sanitizada
     */
    public static function sanitizeUrl(string $url): string
    {
        // Solo remover patrones que claramente son intentos de SQL injection
        // NO remover palabras SQL que forman parte de nombres legítimos de rutas

        // Caracteres claramente maliciosos que siempre deben removerse
        $alwaysDangerous = ["'", '"', ';', '--', '/*', '*/'];

        // Remover caracteres claramente maliciosos
        foreach ($alwaysDangerous as $char) {
            $url = str_replace($char, '', $url);
        }

        // Patrones de SQL injection MUY específicos (solo ataques reales)
        $sqlInjectionPatterns = [
            // Procedimientos almacenados específicos
            '/\b(xp_cmdshell|sp_executesql|xp_regwrite)\b/i',
            // SQL injection con contexto completo muy específico
            '/\b(drop|create)\s+(table|database|schema)\s+\w+/i',
            '/\b(delete|insert|update)\s+(from|into|set)\s+\w+/i',
            '/\bunion\s+(all\s+)?select\s+/i',
            '/\bselect\s+[\w\*,\s]+\s+from\s+\w+/i',
            // Patrones después de punto y coma (inyección de comandos)
            '/;\s*(drop|delete|insert|update|select|create)\s+/i',
            // Comentarios SQL específicos con contexto
            '/\s+(or|and)\s+[\w\s]*--/i',
            '/\s+(or|and)\s+[\w\s]*\/\*/i',
        ];

        // Aplicar solo patrones muy específicos
        foreach ($sqlInjectionPatterns as $pattern) {
            $url = preg_replace($pattern, '', $url);
        }

        // Escapar caracteres especiales adicionales manteniendo funcionalidad
        return filter_var($url, FILTER_SANITIZE_URL);
    }

    /**
     * Genera un token CSRF.
     *
     * @return string Token CSRF
     */
    /**
     * Nonce de CSP de esta petición: el mismo para la cabecera y para cada <script> de la página.
     */
    public static function cspNonce(): string
    {
        return self::$cspNonce ??= base64_encode(random_bytes(16));
    }

    /**
     * Valida un token CSRF.
     *
     * @param string|null $token Token a validar
     *
     * @return bool True si es válido, false en caso contrario
     *
     * @deprecated 3.2 Usar Functions::validateCSRFToken(), que es la que usa el Router. Se elimina en 4.0.
     */
    public static function validateCsrfToken(#[SensitiveParameter] ?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!$token || !isset($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Limpia y valida una URL manteniendo funcionalidad.
     *
     * @param string $url URL a validar
     *
     * @return string URL validada
     */
    public static function validateAndCleanUrl(string $url): string
    {
        // Primero aplicar sanitización básica
        $cleanUrl = self::sanitizeUrl($url);

        // Normalizar la URL (lógica original)
        $cleanUrl = trim($cleanUrl, '/');
        $cleanUrl = parse_url($cleanUrl, PHP_URL_PATH) ?: $cleanUrl;

        // Normalizar múltiples barras consecutivas a una sola barra
        $cleanUrl = preg_replace('#/+#', '/', $cleanUrl);

        // Eliminar barras al final si quedaron después de la normalización
        $cleanUrl = rtrim($cleanUrl, '/');

        return '/' . $cleanUrl;
    }

    /**
     * Verifica si un identificador ha superado el límite de solicitudes permitido.
     *
     * @param string $identifier Identificador único para el IP + ruta
     * @param string $rule Nombre de la regla de límite a aplicar
     *
     * @return array|true Datos de error si el límite es excedido, true si está dentro del límite
     */
    public static function checkRateLimit(string $identifier, string $rule = 'global'): array|true
    {
        global $config;
        // Obtener configuración
        $settings = $config['rate_limiting'] ?? [];
        if (!($settings['enabled'] ?? false)) {
            return true; // Rate limiting no habilitado
        }

        $rules = $settings['rules'][$rule] ?? $settings['rules']['global'] ?? [];

        if (!($rules['enabled'] ?? false)) {
            return true; // Regla específica no habilitada
        }

        $rateData = self::incrementLocked(self::generateKey($identifier, $rule), (int) $rules['window']);

        // Limpieza ocasional de contadores vencidos (1 de cada `cleanup_probability` peticiones)
        if (random_int(1, max(1, (int) ($settings['cleanup_probability'] ?? 100))) === 1) {
            self::cleanExpiredLimits();
        }

        // Verificar si excede el límite
        if ($rateData['count'] > $rules['requests']) {
            return [
                'success' => 0,
                'message' => 'Rate limit exceeded',
                'errors' => [
                    'retry_after' => $rules['window'] - (time() - $rateData['window_start']),
                    'limit' => $rules['requests'],
                    'remaining' => 0,
                    'reset_time' => date('c', $rateData['window_start'] + $rules['window']),
                ],
                'code' => 429,
            ];
        }

        return true;
    }

    /**
     * Verificación de rate limiting global aplicada automáticamente por el Router.
     *
     * @param object $request Objeto Request con información de la petición
     *
     * @return array|true Datos de error si el límite es excedido, true si está dentro del límite
     */
    public static function checkGlobalRateLimit($request): array|true
    {
        $ip = $request->getIp();
        $identifier = $ip . ':global';

        return self::checkRateLimit($identifier, 'global');
    }

    /**
     * Limpia límites expirados.
     */
    public static function cleanExpiredLimits(): void
    {
        global $config;

        // Verificar que la configuración de rate limiting existe
        if (!isset($config['rate_limiting']) || !isset($config['rate_limiting']['cache_dir'])) {
            return;
        }

        $dir = $config['rate_limiting']['cache_dir'];
        $files = glob($dir . '*.json');

        if (!$files) {
            return;
        }

        // La ventana más larga de todas las reglas: un contador vigente para alguna regla no se borra
        $defaultWindow = max([3600, ...array_column($config['rate_limiting']['rules'] ?? [], 'window')]);

        foreach ($files as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (!isset($data['window_start']) || !self::isWithinWindow((int) $data['window_start'], $defaultWindow)) {
                @unlink($file); // Eliminar archivo si expiró o quedó corrupto
            }
        }
    }

    /** @internal Testing\TestClient lo llama entre peticiones: este estado vale para una sola petición. */
    public static function resetRequestState(): void
    {
        self::$cspNonce = null;
    }

    /**
     * Genera una clave única para almacenar los límites según IP y ruta.
     */
    private static function generateKey(string $identifier, string $rule): string
    {
        return hash('sha256', $identifier . '::' . $rule);
    }

    /**
     * Suma una petición al contador de la clave y devuelve su estado. Lectura y escritura ocurren
     * bajo un bloqueo exclusivo, para que peticiones simultáneas no se pisen ni se pierdan.
     *
     * @return array{count: int, window_start: int}
     */
    private static function incrementLocked(string $key, int $window): array
    {
        global $config;

        $cacheDir = $config['rate_limiting']['cache_dir'];
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0o2775, true);
        }

        $fresh = ['count' => 1, 'window_start' => time()];
        $handle = @fopen($cacheDir . $key . '.json', 'c+');
        if ($handle === false) {
            // Sin almacenamiento no se puede contar: se registra y la petición continúa
            Loggin::error('Rate limiting sin almacenamiento escribible: ' . $cacheDir, 'security');

            return $fresh;
        }

        flock($handle, LOCK_EX);
        $data = json_decode((string) stream_get_contents($handle), true);

        if (
            is_array($data)
            && isset($data['window_start'])
            && self::isWithinWindow((int) $data['window_start'], $window)
        ) {
            $data = ['count' => (int) ($data['count'] ?? 0) + 1, 'window_start' => (int) $data['window_start']];
        } else {
            $data = $fresh;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($data));
        flock($handle, LOCK_UN);
        fclose($handle);

        return $data;
    }

    /**
     * Verifica si la ventana de tiempo aún es válida.
     */
    private static function isWithinWindow(int $windowStart, int $windowDuration): bool
    {
        return (time() - $windowStart) < $windowDuration;
    }
}
