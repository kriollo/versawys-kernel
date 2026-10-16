<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use Symfony\Polyfill\Ctype\Ctype;
use versaWYS\kernel\cli\ConfigManager;

/**
 * Lo que el servidor necesita para correr la aplicación. Una sola revisión para el asistente de
 * instalación (/install) y para `versaCLI status`, así nunca dicen cosas distintas. Cada punto que
 * falla dice cómo corregirlo, en un hosting con cPanel y en un servidor Linux.
 */
final class SystemCheck
{
    /** Extensiones que usa el panel base, además de las que piden las dependencias */
    private const array PROJECT_EXTENSIONS = [
        'pdo' => 'Base de datos',
        'json' => 'Intercambio de datos JSON',
        'mbstring' => 'Textos UTF-8',
        'intl' => 'Fechas, números e idioma',
        'openssl' => 'Cifrado (Crypt, AES-256-GCM) y conexiones seguras',
        'fileinfo' => 'Tipo real de los archivos subidos',
        'curl' => 'Llamadas a otros servicios',
        'zip' => 'Archivos Office y zip',
        'xml' => 'Procesamiento XML',
        'dom' => 'DOM/XML de las librerías',
    ];

    /** Paquete de Debian/Ubuntu de cada extensión (las que no vienen con PHP base) */
    private const array LINUX_PACKAGES = [
        'pdo_pgsql' => 'php-pgsql',
        'pdo_mysql' => 'php-mysql',
        'curl' => 'php-curl',
        'mbstring' => 'php-mbstring',
        'zip' => 'php-zip',
        'gd' => 'php-gd',
        'xml' => 'php-xml',
        'dom' => 'php-xml',
        'simplexml' => 'php-xml',
        'xmlreader' => 'php-xml',
        'xmlwriter' => 'php-xml',
        'redis' => 'php-redis',
        'intl' => 'php-intl',
    ];

    /** Polyfills de Symfony que reemplazan la extensión si falta */
    private const array POLYFILLS = [
        'ctype' => Ctype::class,
        'mbstring' => 'Symfony\\Polyfill\\Mbstring\\Mbstring',
        'iconv' => 'Symfony\\Polyfill\\Iconv\\Iconv',
    ];

    /**
     * @param array<string, mixed> $config la configuración del proyecto (vacía antes de instalar)
     *
     * @return list<array{group: string, label: string, ok: bool, fix: string}>
     */
    public static function run(array $config = []): array
    {
        $checks = [[
            'group' => 'PHP',
            'label' => 'PHP 8.3 o superior (actual: ' . PHP_VERSION . ')',
            'ok' => PHP_VERSION_ID >= 80_300,
            'fix' => 'En cPanel: «Seleccionar versión de PHP» o «MultiPHP Manager». En Linux: instala PHP 8.3 o superior.',
        ]];

        foreach (self::extensions($config) as $extension => $reason) {
            $checks[] = [
                'group' => 'Extensiones de PHP',
                'label' => "{$extension} ({$reason})",
                'ok' => self::loaded($extension),
                'fix' => self::extensionFix($extension),
            ];
        }

        if (self::driver($config) === '') {
            // Antes de instalar no se sabe el motor: basta uno
            $checks[] = [
                'group' => 'Extensiones de PHP',
                'label' => 'pdo_mysql o pdo_pgsql (driver de la base de datos)',
                'ok' => extension_loaded('pdo_mysql') || extension_loaded('pdo_pgsql'),
                'fix' =>
                    'Activa pdo_mysql (MySQL/MariaDB) o pdo_pgsql (PostgreSQL). ' . self::extensionFix('pdo_mysql'),
            ];
        }

        foreach (self::writableDirs($config) as $dir) {
            $checks[] = [
                'group' => 'Carpetas',
                'label' => "{$dir} con permiso de escritura",
                'ok' => self::writable($dir),
                'fix' => 'Crea la carpeta si falta y dale permisos 775 (cPanel: Administrador de archivos → Permisos; Linux: chmod -R 775).',
            ];
        }

        $checks[] = [
            'group' => 'Archivos',
            'label' => 'Frontend compilado (public/dashboard)',
            'ok' => is_file('public/dashboard/css/dashboard.css') && self::hasFiles('public/dashboard/js/*.js'),
            'fix' => 'Compila en tu equipo con «pnpm install && pnpm compile» y sube la carpeta public/ completa: el hosting no necesita Node.',
        ];

        if (PHP_SAPI !== 'cli') {
            // Con la cookie de sesión `secure`, por http el navegador no la guarda: el instalador y el login
            // fallarían con «los datos enviados no son correctos» sin decir por qué
            $secure = (bool) ($config['session']['user_cookie']['secure'] ?? true);
            $checks[] = [
                'group' => 'Web',
                'label' => 'El sitio se abre con HTTPS',
                'ok' => !$secure || self::https() || self::local(),
                'fix' =>
                    'Activa SSL (en cPanel: «SSL/TLS Status» → AutoSSL) y abre el sitio con https://. '
                        . 'Solo para una prueba sin SSL: "session": {"user_cookie": {"secure": false}} en config.json.',
            ];
        }

        return $checks;
    }

    /** @param list<array{ok: bool}> $checks */
    public static function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Las del proyecto, las que exigen las dependencias instaladas y las que pide la configuración.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, string> extensión => para qué se usa
     */
    public static function extensions(array $config = []): array
    {
        $required = self::PROJECT_EXTENSIONS;
        foreach (self::dependencyExtensions() as $extension) {
            $required[$extension] ??= 'La pide una dependencia';
        }

        $driver = self::driver($config);
        if ($driver !== '') {
            $pg = in_array($driver, ['postgresql', 'pgsql'], true);
            $required[$pg ? 'pdo_pgsql' : 'pdo_mysql'] = 'Driver de la base de datos';
        }

        if ((bool) ($config['redis']['enabled'] ?? false)) {
            $required['redis'] = 'Redis habilitado en la configuración';
        }

        ksort($required);

        return $required;
    }

    /**
     * Las extensiones `ext-*` que piden los paquetes instalados (vendor/composer/installed.json).
     *
     * @return list<string>
     */
    private static function dependencyExtensions(): array
    {
        $file = dirname((string) (new ReflectionClass(ClassLoader::class))->getFileName()) . '/installed.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $packages = is_array($data) ? $data['packages'] ?? $data : [];

        // Las herramientas de desarrollo (phpunit, mago…) no se instalan en producción (--no-dev)
        $dev = is_array($data) ? (array) ($data['dev-package-names'] ?? []) : [];

        $extensions = [];
        foreach (is_array($packages) ? $packages : [] as $package) {
            if (in_array($package['name'] ?? null, $dev, true)) {
                continue;
            }

            foreach (array_keys((array) ($package['require'] ?? [])) as $dependency) {
                if (!is_string($dependency) || !str_starts_with($dependency, 'ext-')) {
                    continue;
                }

                $extensions[] = strtolower(substr($dependency, 4));
            }
        }

        return array_values(array_unique($extensions));
    }

    /**
     * El motor elegido, solo si la base de datos ya está configurada: la configuración recién creada
     * trae un motor por defecto, pero el instalador todavía no preguntó cuál usar.
     *
     * @param array<string, mixed> $config
     */
    private static function driver(array $config): string
    {
        return trim((string) ($config['database']['database'] ?? '')) === ''
            ? ''
            : (string) ($config['database']['driver'] ?? '');
    }

    private static function hasFiles(string $pattern): bool
    {
        $files = glob($pattern);

        return $files !== false && $files !== [];
    }

    private static function loaded(string $extension): bool
    {
        return (
            extension_loaded($extension)
            || isset(self::POLYFILLS[$extension]) && class_exists(self::POLYFILLS[$extension])
        );
    }

    private static function extensionFix(string $extension): string
    {
        $package = self::LINUX_PACKAGES[$extension] ?? null;

        return (
            'En cPanel: «Seleccionar versión de PHP» → Extensiones, o pídela al hosting.'
            . ($package === null ? '' : " En Debian/Ubuntu: sudo apt install {$package}.")
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function writableDirs(array $config): array
    {
        $dirs = [ConfigManager::dir(), 'versaWYS/cache', 'versaWYS/logs'];
        foreach (['avatars', 'temp'] as $asset) {
            $dist = $config['assets']['dashboard'][$asset]['dist'] ?? null;
            if (is_string($dist) && $dist !== '') {
                $dirs[] = $dist;
            }
        }

        return array_values(array_unique($dirs));
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
}
