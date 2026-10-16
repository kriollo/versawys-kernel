<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use SensitiveParameter;
use versaWYS\kernel\cli\ConfigManager;

/**
 * Modo mantenimiento: `php versaCLI app:down` y todo responde 503 (la API en JSON) hasta `app:up`.
 * Quien abre `/<clave>` una vez queda con una cookie y sigue usando el sitio normalmente.
 *
 * El estado vive en un archivo junto a config.json: sobrevive a `cache:clear` y a un reinicio.
 */
final class Maintenance
{
    public const string COOKIE = 'versa_maintenance';

    public static function file(): string
    {
        return dirname(ConfigManager::path()) . '/down.json';
    }

    /**
     * @return array{secret: string, retry: int, message: string, since: string}
     */
    public static function down(string $message = '', int $retry = 60, #[SensitiveParameter] string $secret = ''): array
    {
        $state = [
            'secret' => $secret !== '' ? $secret : bin2hex(random_bytes(16)),
            'retry' => max(1, $retry),
            'message' => $message !== '' ? $message : 'Estamos haciendo mantenimiento. Volvemos en unos minutos.',
            'since' => date('Y-m-d H:i:s'),
        ];
        file_put_contents(self::file(), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        return $state;
    }

    public static function up(): bool
    {
        return is_file(self::file()) && unlink(self::file());
    }

    /** @return array{secret: string, retry: int, message: string, since: string}|null */
    public static function status(): ?array
    {
        $raw = is_file(self::file()) ? file_get_contents(self::file()) : false;
        $state = $raw === false ? null : json_decode($raw, true);

        /** @var array{secret: string, retry: int, message: string, since: string}|null */
        return is_array($state) ? $state : null;
    }

    /**
     * Lo llama el Router antes de resolver la ruta. Devuelve true si ya respondió (503 o la entrada
     * con la clave) y false si la petición sigue su curso.
     *
     * @internal
     */
    public static function handle(Request $request, string $url): bool
    {
        $state = self::status();
        if ($state === null) {
            return false;
        }

        $pass = hash_hmac('sha256', $state['secret'], Crypt::key('maintenance'));
        if ($url === '/' . $state['secret']) {
            Output::cookie(self::COOKIE, $pass, [
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => Cookie::defaultSecure(),
                'expires' => time() + 43_200,
            ]);
            Response::redirect('/');

            return true;
        }

        if (hash_equals($pass, (string) ($_COOKIE[self::COOKIE] ?? ''))) {
            return false;
        }

        Output::header('Retry-After: ' . $state['retry']);
        if ($request->isApiCall()) {
            Response::json(['success' => 0, 'message' => $state['message'], 'maintenance' => true], 503);

            return true;
        }

        Output::status(503);
        $message = htmlspecialchars($state['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo
            '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<meta http-equiv="refresh" content="'
                . $state['retry']
                . '"><title>En mantenimiento</title><style>'
                . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;'
                . 'font:16px/1.5 system-ui,sans-serif;background:#f1f5f9;color:#0f172a;text-align:center}'
                . 'main{max-width:480px}h1{font-size:22px;margin:0 0 8px}'
                . '@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e2e8f0}}'
                . '</style></head><body><main><h1>En mantenimiento</h1><p>'
                . $message
                . '</p></main></body></html>';

        return true;
    }
}
