<?php

declare(strict_types=1);

namespace versaWYS\kernel;

/**
 * Enlaces firmados que vencen: quien tiene el enlace entra sin sesión, solo a esa URL y hasta que vence.
 * Para descargas, confirmaciones por correo o archivos de Storage.
 *
 *     $url = SignedUrl::make("/facturas/{$id}/pdf", 3600);              // una hora
 *     $enlace = $request->getBaseUrl() . $url;                          // absoluto, para un correo
 *
 *     Router::get('/facturas/{id}/pdf', [...])->middleware([[SignedUrl::class, 'check']]);
 *
 * La firma cubre la ruta y todos los parámetros: cambiar cualquiera, o el vencimiento, la invalida.
 */
final class SignedUrl
{
    /**
     * @param array<string, scalar> $params parámetros que también quedan firmados
     */
    public static function make(string $path, int $seconds = 3600, array $params = []): string
    {
        $params['expires'] = time() + $seconds;
        unset($params['signature']);
        $params['signature'] = self::signature($path, $params);

        return $path . '?' . http_build_query($params);
    }

    /** ¿La URL tiene una firma válida y vigente? */
    public static function isValid(string $url, ?int $now = null): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        $signature = $params['signature'] ?? null;
        $expires = $params['expires'] ?? null;
        if (!is_string($signature) || !is_numeric($expires) || (int) $expires < ($now ?? time())) {
            return false;
        }

        unset($params['signature']);

        return hash_equals(self::signature($path, $params), $signature);
    }

    /**
     * Middleware: la petición actual debe venir con una firma válida y vigente; si no, 403.
     *
     * @return true|array{success: int, message: string, code: int}
     */
    public function check(): true|array
    {
        if (self::isValid($_SERVER['REQUEST_URI'] ?? '')) {
            return true;
        }

        return ['success' => 0, 'message' => 'El enlace venció o no es válido.', 'code' => 403];
    }

    /** @param array<array-key, mixed> $params */
    private static function signature(string $path, array $params): string
    {
        ksort($params);

        return hash_hmac('sha256', $path . '?' . http_build_query($params), Crypt::key('url'));
    }
}
