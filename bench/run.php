<?php

declare(strict_types=1);

/*
 * Línea base del kernel, en memoria y sin servidor: php bench/run.php [iteraciones]
 * Imprime el entorno y la mediana / p95 de cada escenario en microsegundos. Comparar solo
 * resultados del mismo equipo y la misma configuración de PHP (OPcache y Xdebug cambian todo).
 */

use versaWYS\kernel\Request;
use versaWYS\kernel\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

$iterations = max(50, (int) ($argv[1] ?? 2000));

$GLOBALS['config'] = [
    'trusted_proxies' => [],
    'rate_limiting' => ['enabled' => false],
    'admin' => ['custom_url' => 'admin'],
    'build' => ['debug' => false],
];

final class BenchController
{
    public function show(): string
    {
        return 'ok';
    }

    public function item(string $id): string
    {
        return $id;
    }
}

/** @return array{p50: float, p95: float} */
function measure(int $iterations, callable $fn): array
{
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $start) / 1000;
    }
    sort($times);

    return ['p50' => $times[intdiv($iterations, 2)], 'p95' => $times[(int) floor($iterations * 0.95)]];
}

function dispatch(string $path): void
{
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '127.0.0.1'];
    $GLOBALS['request'] = new Request();
    ob_start();
    (new Router())->resolve();
    ob_end_clean();
}

// 60 rutas, como un panel mediano: la mitad con parámetros
$register = static function (): void {
    for ($i = 0; $i < 30; $i++) {
        Router::get("/admin/modulo{$i}", [BenchController::class, 'show']);
        Router::get("/admin/modulo{$i}/api/item/{id}", [BenchController::class, 'item']);
    }
};
$register();

$results = [
    'Registrar 60 rutas' => measure(max(50, intdiv($iterations, 10)), $register),
    'Resolver ruta exacta' => measure($iterations, static fn() => dispatch('/admin/modulo29')),
    'Resolver ruta con parámetro' => measure($iterations, static fn() => dispatch('/admin/modulo29/api/item/42')),
    'Crear Request' => measure($iterations, static function (): void {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'REMOTE_ADDR' => '127.0.0.1'];
        new Request();
    }),
];

$cpu = is_readable('/proc/cpuinfo')
&& preg_match('/model name\s*:\s*(.+)/', (string) file_get_contents('/proc/cpuinfo'), $m)
    ? trim($m[1])
    : php_uname('m');
$opcache =
    function_exists('opcache_get_status')
    && is_array($status = @opcache_get_status(false))
    && ($status['opcache_enabled'] ?? false);
$xdebug = extension_loaded('xdebug') && !in_array((string) ini_get('xdebug.mode'), ['', 'off'], true);

printf(
    "PHP %s (%s) · OPcache %s · Xdebug %s · %s · %d iteraciones\n\n",
    PHP_VERSION,
    PHP_SAPI,
    $opcache ? 'sí' : 'no',
    $xdebug ? 'activo' : 'no',
    $cpu,
    $iterations,
);
printf("| Escenario | p50 (µs) | p95 (µs) |\n|---|---|---|\n");
foreach ($results as $name => $r) {
    printf("| %s | %.1f | %.1f |\n", $name, $r['p50'], $r['p95']);
}
