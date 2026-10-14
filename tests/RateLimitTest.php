<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\RateLimitMiddleware;
use versaWYS\kernel\Request;
use versaWYS\kernel\SecurityUtils;

final class RateLimitTest extends TestCase
{
    private string $dir;

    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $this->dir = sys_get_temp_dir() . '/versawys-rl-' . bin2hex(random_bytes(4)) . '/';
        $config = self::config($this->dir);
    }

    protected function tearDown(): void
    {
        global $config;

        $config = $this->originalConfig;
        foreach (glob($this->dir . '*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testItBlocksWithA429AfterTheLimit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue(SecurityUtils::checkRateLimit('198.51.100.7', 'login'));
        }

        $blocked = SecurityUtils::checkRateLimit('198.51.100.7', 'login');

        self::assertIsArray($blocked);
        self::assertSame(429, $blocked['code']);
        // Otra IP tiene su propio contador
        self::assertTrue(SecurityUtils::checkRateLimit('198.51.100.8', 'login'));
    }

    public function testTheCountRestartsWhenTheWindowExpires(): void
    {
        for ($i = 0; $i < 4; $i++) {
            SecurityUtils::checkRateLimit('198.51.100.7', 'login');
        }

        // La ventana de este contador empezó hace más de 60 s
        foreach (glob($this->dir . '*.json') ?: [] as $file) {
            file_put_contents($file, json_encode(['count' => 99, 'window_start' => time() - 61]));
        }

        self::assertTrue(SecurityUtils::checkRateLimit('198.51.100.7', 'login'));
    }

    public function testOneAccountIsLimitedEvenFromManyAddresses(): void
    {
        global $request;

        // Un ataque repartido entre IPs contra el mismo correo: se cuenta también por cuenta
        $attempt = static function (string $ip, string $email): array|true {
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/admin/login', 'REMOTE_ADDR' => $ip];
            $_REQUEST = ['email' => $email];
            $GLOBALS['request'] = new Request();
            $GLOBALS['request']->setParam('email', $email);

            return RateLimitMiddleware::checkLoginLimit();
        };

        self::assertTrue($attempt('198.51.100.1', 'ana@ejemplo.cl'));
        self::assertTrue($attempt('198.51.100.2', 'ANA@ejemplo.cl'));
        self::assertTrue($attempt('198.51.100.3', ' ana@ejemplo.cl '));

        $blocked = $attempt('198.51.100.4', 'Ana@Ejemplo.cl');
        self::assertIsArray($blocked);
        self::assertSame(429, $blocked['code']);
        // Otra cuenta desde una IP nueva sigue pudiendo entrar
        self::assertTrue($attempt('198.51.100.5', 'pedro@ejemplo.cl'));
        unset($request);
    }

    public function testConcurrentRequestsAreAllCounted(): void
    {
        // flock: 8 procesos a la vez sobre el mismo contador no pierden incrementos
        $script = tempnam(sys_get_temp_dir(), 'rl') . '.php';
        file_put_contents($script, '<?php
            require ' . var_export(
            __DIR__ . '/../vendor/autoload.php',
            true,
        ) . ';
            $GLOBALS["config"] = ' . var_export(self::config($this->dir, 100_000), true) . (
            ';
            for ($i = 0; $i < 25; $i++) { ' . SecurityUtils::class . '::checkRateLimit("203.0.113.9", "login"); }
        '
        ));

        $processes = [];
        for ($p = 0; $p < 8; $p++) {
            $processes[] = proc_open([PHP_BINARY, $script], [], $pipes);
        }

        foreach ($processes as $process) {
            proc_close($process);
        }

        unlink($script);

        $files = glob($this->dir . '*.json') ?: [];
        self::assertCount(1, $files);
        self::assertSame(200, json_decode((string) file_get_contents($files[0]), true)['count']);
    }

    public function testWithoutWritableStorageTheRequestContinuesAndIsLogged(): void
    {
        global $config;
        // Una ruta bajo un archivo: no se puede crear la carpeta
        $blocker = tempnam(sys_get_temp_dir(), 'rl');
        $config = self::config($blocker . '/sub/', 1);
        $log = tempnam(sys_get_temp_dir(), 'phplog');
        $previous = ini_set('error_log', $log);

        try {
            // Fallo abierto: bloquear dejaría a todos sin poder iniciar sesión (ver SECURITY.md)
            self::assertTrue(SecurityUtils::checkRateLimit('198.51.100.7', 'login'));
            self::assertTrue(SecurityUtils::checkRateLimit('198.51.100.7', 'login'));
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($blocker);
        }

        $logs =
            (string) file_get_contents($log)
            . implode('', array_map(file_get_contents(...), glob(sys_get_temp_dir() . '/versawys-test-logs/*') ?: []));
        unlink($log);
        self::assertStringContainsString('Rate limiting sin almacenamiento escribible', $logs);
    }

    /** @return array<string, mixed> */
    private static function config(string $dir, int $requests = 3): array
    {
        return [
            'rate_limiting' => [
                'enabled' => true,
                'cache_dir' => $dir,
                'cleanup_probability' => 1_000_000,
                'rules' => ['login' => ['requests' => $requests, 'window' => 60, 'enabled' => true]],
            ],
            'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/'],
        ];
    }
}
