<?php

declare(strict_types=1);

use Composer\InstalledVersions;

// Valores por defecto de la configuración. `config.json` solo necesita declarar lo que cambia:
// config.php los fusiona con array_replace_recursive y `versaCLI config:init` genera el
// config.json inicial a partir de este mismo array.
return [
    'framework' =>
        'versaWYS-PHP '
            . (
                InstalledVersions::isInstalled('versawys/kernel')
                    ? InstalledVersions::getPrettyVersion('versawys/kernel')
                    : 'dev'
            ),
    'database' => [
        'engine' => 'pdo',
        'driver' => 'mysql', // mysql | postgresql
        'host' => 'localhost',
        'port' => 3306,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'database' => '',
        'username' => '',
        'password' => '',
        'log_path' => './versaWYS/logs/orm/',
    ],
    'admin' => [
        'custom_url' => 'admin',
        'hide_default' => false,
        'redirect_404' => true,
    ],
    'twig' => [
        'cache' => true,
        'compiled_dir' => './versaWYS/cache/twig/.cache',
        'templates_dir' => './app/templates',
        'strict_variables' => false,
        'auto_escape' => true,
        'default_template_404' => 'versaWYS/404',
    ],
    'build' => [
        'debug' => false,
        'name' => 'versaWYS',
        'version' => '1.0.0',
        'timezone' => 'America/Santiago',
        'locale' => 'es_CL.UTF-8',
        'charset' => 'utf-8',
        // URL pública (https://app.ejemplo.cl). Vacía: se deduce de la petición. Fijarla en producción
        'base_url' => '',
    ],
    // IPs o rangos CIDR de los proxies propios (balanceador, Cloudflare): solo desde ellos se
    // aceptan X-Forwarded-For / -Proto / -Host. Rangos inválidos se ignoran y se registran.
    'trusted_proxies' => [],
    'auth' => [
        'inactive_account_days' => 30,
        'expiration_days_password' => 90,
        'password_policy' => [
            'large' => 8,
            'uppercase' => true,
            'lowercase' => true,
            'number' => true,
            'special_chars' => true,
        ],
    ],
    'session' => [
        'key' => '', // config:init genera una clave aleatoria
        'lifetime' => 36_000,
        'user_cookie' => [
            'domain' => '',
            'enable' => true,
            'key_encript' => '', // config:init genera una clave aleatoria
            'lifetime' => 36_000,
            'lifetime_remember' => 2_592_000,
            'secure' => true,
            'http_only' => true,
            'same_site' => 'Lax',
            'strict_mode' => true,
        ],
    ],
    'api' => ['auth' => true],
    'login_attempt' => ['max' => 5, 'time' => 120],
    'mail' => [
        'host' => '',
        'port' => 587,
        'transport' => 'smtp',
        'secure' => 'tls',
        'verify_peer' => true, // false solo para SMTP interno con certificado autofirmado
        'name_from' => '',
        'username' => '',
        'password' => '',
    ],
    'cors' => ['allowed_origins' => []],
    // Permissions-Policy: cámara, micrófono y ubicación solo para el propio sitio; pagos, USB, puerto serie
    // y Bluetooth, para nadie. Vacío para no enviar la cabecera
    'permissions_policy' => 'camera=(self), microphone=(self), geolocation=(self), payment=(), usb=(), serial=(), bluetooth=()',
    // nonce: exige el nonce de la petición en los <script> inline (ver csp_nonce() en Twig)
    'csp' => ['enabled' => true, 'nonce' => false, 'unsafe_eval' => true],
    'websocket_service' => ['ws_url' => ''],
    'logging' => [
        'dir' => './versaWYS/logs/',
        'min_level' => 'INFO',
        'default_module' => 'app',
        'use_console' => false,
        'format' => 'json',
        'max_file_size' => 10_485_760,
        'retention_days' => 30,
        'include_stack_trace' => true,
        'timezone' => 'America/Santiago',
    ],
    'redis' => [
        'enabled' => false,
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        'database' => 0,
        'persistent' => true,
        'persistent_id' => 'versa_redis',
        'timeout' => 2.5,
        'default_expiration' => 3600,
    ],
    // Caché de la aplicación (Cache): 'file' o 'redis' (usa la conexión de 'redis')
    // prefix: espacio de claves en Redis; vacío = uno propio derivado de session.key
    'cache' => ['driver' => 'file', 'dir' => 'versaWYS/cache/data/', 'prefix' => ''],
    // Cola de trabajos (Queue): 'database' (tabla versa_jobs + queue:work) o 'sync' (en el acto)
    'queue' => ['driver' => 'database'],
    // Profiler de desarrollo (/_profiler): solo con build.debug; el panel responde desde la propia máquina y estas IPs
    'profiler' => ['enabled' => true, 'allowed_ips' => []],
    // Archivos de la aplicación (Storage), fuera de lo que sirve el servidor web
    'storage' => ['root' => 'versaWYS/storage'],
    'rate_limiting' => [
        'enabled' => true,
        'storage' => 'file',
        'cache_dir' => './versaWYS/cache/rate_limits/',
        'cleanup_probability' => 100,
        'rules' => [
            'global' => ['requests' => 100, 'window' => 60, 'enabled' => true, 'description' => 'Límite global por IP'],
            'api' => [
                'requests' => 30,
                'window' => 60,
                'enabled' => true,
                'description' => 'Límite para endpoints API',
            ],
            'login' => [
                'requests' => 5,
                'window' => 300,
                'enabled' => true,
                'description' => 'Límite para intentos de login',
            ],
            'upload' => [
                'requests' => 10,
                'window' => 120,
                'enabled' => true,
                'description' => 'Límite para subida de archivos',
            ],
            'sensitive' => [
                'requests' => 3,
                'window' => 600,
                'enabled' => true,
                'description' => 'Límite para endpoints sensibles',
            ],
        ],
    ],
    'assets' => [
        'dashboard' => [
            'js' => ['src' => 'src/dashboard/js', 'dist' => 'public/dashboard/js'],
            'css' => ['src' => 'src/dashboard/css', 'dist' => 'public/dashboard/css'],
            'img' => ['dist' => 'public/dashboard/img'],
            'avatars' => ['dist' => 'public/dashboard/img/avatars'],
            'store' => ['dist' => 'public/dashboard/store'],
            'temp' => ['dist' => 'public/temp'],
        ],
    ],
];
