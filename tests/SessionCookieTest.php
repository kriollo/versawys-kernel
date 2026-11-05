<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionCookieTest extends TestCase
{
    public function testTheSessionCookieIsHardenedEvenWithAnInsecurePhpIni(): void
    {
        // Otro proceso: session_start() no puede correr dentro de la suite, que ya envió salida
        $script = tempnam(sys_get_temp_dir(), 'ses') . '.php';
        $config = require __DIR__ . '/../src/config/config.default.php';
        $config['logging']['dir'] = sys_get_temp_dir() . '/versawys-test-logs/';
        file_put_contents($script, '<?php
            require '
        . var_export(__DIR__ . '/../vendor/autoload.php', true)
        . ';
            $GLOBALS["config"] = '
        . var_export($config, true)
        . ';
            session_save_path(sys_get_temp_dir());
            new versaWYS\kernel\Session(3600);
            echo json_encode(array_map("ini_get", ["session.cookie_httponly", "session.cookie_secure", "session.cookie_samesite", "session.use_strict_mode", "session.use_only_cookies", "session.use_trans_sid"])) . PHP_EOL;
        ');

        $output = (string) shell_exec(
            escapeshellarg(PHP_BINARY)
            . ' -d session.use_only_cookies=0 -d session.use_trans_sid=1 '
            . escapeshellarg($script)
            . ' 2>&1',
        );
        unlink($script);

        // PHP 8.4 avisa que esos valores del php.ini son obsoletos: solo interesa la línea JSON
        preg_match('/^\[.*\]$/m', $output, $json);
        self::assertSame(['1', '1', 'Lax', '1', '1', '0'], json_decode($json[0] ?? 'null', true), $output);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function requests(): iterable
    {
        yield 'GET /up' => ['GET', '/up', false];
        yield 'HEAD /up' => ['HEAD', '/up', false];
        yield 'GET /up/ con barra final' => ['GET', '/up/', false];
        yield 'GET /up con query string' => ['GET', '/up?check=1', false];
        yield 'POST /up' => ['POST', '/up', true];
        yield 'GET /upload' => ['GET', '/upload', true];
        yield 'GET /admin/login' => ['GET', '/admin/login', true];
    }

    /** /up lo consultan los monitores cada pocos segundos: no debe crear una sesión por consulta */
    #[DataProvider('requests')]
    public function testOnlyTheHealthCheckSkipsTheSession(string $method, string $uri, bool $expected): void
    {
        $script = tempnam(sys_get_temp_dir(), 'ses') . '.php';
        $config = require __DIR__ . '/../src/config/config.default.php';
        $config['logging']['dir'] = sys_get_temp_dir() . '/versawys-test-logs/';
        file_put_contents($script, '<?php
            require '
        . var_export(__DIR__ . '/../vendor/autoload.php', true)
        . ';
            $GLOBALS["config"] = '
        . var_export($config, true)
        . ';
            $_SERVER["REQUEST_METHOD"] = '
        . var_export($method, true)
        . ';
            $_SERVER["REQUEST_URI"] = '
        . var_export($uri, true)
        . ';
            session_save_path(sys_get_temp_dir());
            new versaWYS\kernel\Session(3600);
            echo session_status() === PHP_SESSION_ACTIVE ? "con-sesion" : "sin-sesion";
        ');

        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
        unlink($script);

        self::assertStringEndsWith($expected ? 'con-sesion' : 'sin-sesion', trim($output), $output);
    }
}
