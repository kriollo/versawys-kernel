<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Throwable;
use versaWYS\kernel\cli\ConfigManager;

/**
 * Primer arranque e instalación pendiente, a prueba de un hosting sin terminal (cPanel):
 *
 * - Sin config.json, el primer request lo crea (clave de sesión propia + token de instalación) y
 *   manda al instalador. Si no puede (permisos, PHP, extensiones), muestra qué corregir.
 * - Mientras la instalación esté pendiente, toda página lleva a /install y la API responde 503.
 *
 * Pendiente = existe el token de instalación (lo crean el primer arranque y `config:init`; el
 * instalador lo borra al terminar) y no existe `installed.lock`. Los proyectos instalados antes de
 * que existiera el instalador, o una aplicación sin instalador, no tienen token: nunca quedan pendientes.
 */
final class Installation
{
    /** Ruta del asistente de instalación del proyecto (app/routes/InstallRoutes.php) */
    public const string PATH = '/install';

    public static function lockFile(): string
    {
        return ConfigManager::dir() . '/installed.lock';
    }

    public static function tokenFile(): string
    {
        return ConfigManager::dir() . '/install.token';
    }

    public static function isPending(): bool
    {
        return is_file(self::tokenFile()) && !is_file(self::lockFile());
    }

    /**
     * Lo que el servidor necesita para correr la aplicación.
     *
     * @return list<array{label: string, ok: bool, fix: string}>
     */
    public static function requirements(): array
    {
        $checks = [
            [
                'label' => 'PHP 8.3 o superior (actual: ' . PHP_VERSION . ')',
                'ok' => PHP_VERSION_ID >= 80_300,
                'fix' => 'En cPanel: «Seleccionar versión de PHP» o «MultiPHP Manager».',
            ],
        ];

        foreach (['intl', 'mbstring', 'pdo', 'json', 'openssl', 'fileinfo'] as $extension) {
            $checks[] = [
                'label' => "Extensión PHP «{$extension}»",
                'ok' => extension_loaded($extension),
                'fix' => 'Actívala en cPanel («Seleccionar versión de PHP» → Extensiones) o pídela al hosting.',
            ];
        }

        $checks[] = [
            'label' => 'Driver de base de datos (pdo_mysql o pdo_pgsql)',
            'ok' => extension_loaded('pdo_mysql') || extension_loaded('pdo_pgsql'),
            'fix' => 'Activa pdo_mysql (MySQL/MariaDB) o pdo_pgsql (PostgreSQL) en las extensiones de PHP.',
        ];

        foreach ([ConfigManager::dir(), 'versaWYS/cache', 'versaWYS/logs'] as $dir) {
            $checks[] = [
                'label' => "Carpeta «{$dir}» con permiso de escritura",
                'ok' => self::writable($dir),
                'fix' => 'Crea la carpeta si falta y dale permisos 775 (Administrador de archivos → Permisos).',
            ];
        }

        // Con la cookie de sesión `secure`, por http el navegador no la guarda: el instalador y el login
        // fallarían con «los datos enviados no son correctos» sin decir por qué
        if (PHP_SAPI !== 'cli') {
            global $config;

            $secure = (bool) ($config['session']['user_cookie']['secure'] ?? true);
            $checks[] = [
                'label' => 'El sitio se abre con HTTPS',
                'ok' => !$secure || self::https() || self::local(),
                'fix' =>
                    'Activa SSL (en cPanel: «SSL/TLS Status» → AutoSSL) y abre el sitio con https://. '
                        . 'Solo para una prueba sin SSL: "session": {"user_cookie": {"secure": false}} en config.json.',
            ];
        }

        return $checks;
    }

    /**
     * Primer arranque web sin config.json: lo crea, o si el servidor no cumple los requisitos,
     * responde con la página que explica qué corregir y termina la petición.
     */
    public static function bootstrap(): void
    {
        if (is_file(ConfigManager::path())) {
            return;
        }

        $failing = array_values(array_filter(self::requirements(), static fn(array $c): bool => !$c['ok']));
        $error = null;
        if ($failing === []) {
            try {
                ConfigManager::createConfig();

                return;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        Output::status(503);
        Output::header('Content-Type: text/html; charset=utf-8');
        echo self::requirementsPage(self::requirements(), $error);
        Output::halt();
    }

    /**
     * Página de requisitos, sin Twig ni base de datos (puede que todavía no existan).
     *
     * @param list<array{label: string, ok: bool, fix: string}> $checks
     */
    public static function requirementsPage(array $checks, ?string $error = null): string
    {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($checks as $c) {
            $rows .=
                '<li class="'
                . ($c['ok'] ? 'ok' : 'no')
                . '"><b>'
                . ($c['ok'] ? '✓' : '✗')
                . '</b> '
                . $h($c['label'])
                . ($c['ok'] ? '' : '<small>' . $h($c['fix']) . '</small>')
                . '</li>';
        }

        return (
            '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Instalación: requisitos</title><style>'
            . 'body{margin:0;font:15px/1.5 system-ui,sans-serif;background:#f1f5f9;color:#0f172a;display:flex;justify-content:center;padding:32px 16px}'
            . 'main{background:#fff;max-width:640px;width:100%;border-radius:12px;padding:28px;box-shadow:0 8px 24px rgba(0,0,0,.08)}'
            . 'h1{font-size:20px;margin:0 0 8px}ul{list-style:none;padding:0}li{padding:8px 0;border-bottom:1px solid #e2e8f0}'
            . '.ok b{color:#16a34a}.no b{color:#dc2626}small{display:block;color:#64748b;margin-left:22px}'
            . '.err{background:#fee2e2;border-radius:8px;padding:10px 12px}'
            . '@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e2e8f0}main{background:#1e293b}li{border-color:#334155}.err{background:#7f1d1d}}'
            . '</style></head><body><main><h1>Antes de instalar</h1>'
            . '<p>El servidor necesita lo siguiente. Corrige lo marcado con ✗ y recarga esta página.</p>'
            . ($error !== null ? '<p class="err">No se pudo crear la configuración: ' . $h($error) . '</p>' : '')
            . '<ul>'
            . $rows
            . '</ul></main></body></html>'
        );
    }

    private static function https(): bool
    {
        if (in_array(strtolower($_SERVER['HTTPS'] ?? ''), ['on', '1'], true)) {
            return true;
        }

        // Detrás de un proxy propio (trusted_proxies), el esquema lo dice X-Forwarded-Proto
        return (
            Request::isTrustedProxy($_SERVER['REMOTE_ADDR'] ?? '')
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        );
    }

    /** Los navegadores tratan localhost como seguro: ahí la cookie `secure` funciona por http */
    private static function local(): bool
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));

        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost');
    }

    private static function writable(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        // Si no existe, basta con poder crearla dentro de su carpeta padre
        $parent = dirname($dir);
        while (!is_dir($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }

        return is_writable($parent);
    }
}
