<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

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
            echo json_encode(array_map("ini_get", ["session.cookie_httponly", "session.cookie_secure", "session.cookie_samesite", "session.use_strict_mode", "session.use_only_cookies", "session.use_trans_sid"]));
        ');

        $output = (string) shell_exec(
            escapeshellarg(PHP_BINARY) . ' -d session.use_only_cookies=0 -d session.use_trans_sid=1 '
                . escapeshellarg($script),
        );
        unlink($script);

        // PHP 8.4 avisa que esos valores del php.ini son obsoletos: solo interesa la línea JSON
        preg_match('/^\[.*\]$/m', $output, $json);
        self::assertSame(['1', '1', 'Lax', '1', '1', '0'], json_decode($json[0] ?? 'null', true), $output);
    }
}
