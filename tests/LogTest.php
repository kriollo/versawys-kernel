<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Loggin;

final class LogTest extends TestCase
{
    public function testSecretsNeverReachTheLogAtAnyDepth(): void
    {
        $context = Loggin::redact([
            'email' => 'ana@ejemplo.cl',
            'password' => 'Clave#2026',
            'params' => ['_csrf_token' => 'abc', 'nombre' => 'Ana', 'headers' => ['Authorization' => 'Bearer x']],
            'api_key' => 'k',
        ]);

        self::assertSame('ana@ejemplo.cl', $context['email']);
        self::assertSame('[oculto]', $context['password']);
        self::assertSame('[oculto]', $context['params']['_csrf_token']);
        self::assertSame('Ana', $context['params']['nombre']);
        self::assertSame('[oculto]', $context['params']['headers']['Authorization']);
        self::assertSame('[oculto]', $context['api_key']);
    }

    public function testTheRequestIdIsTheSameForTheWholeRequest(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', Loggin::requestId());
        self::assertSame(Loggin::requestId(), Loggin::requestId());
    }

    public function testTheRequestIdFromATrustedProxyIsKept(): void
    {
        // En otro proceso: requestId() se fija una vez por petición
        $script = tempnam(sys_get_temp_dir(), 'log') . '.php';
        file_put_contents($script, '<?php
            require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';
            $GLOBALS["config"] = ["trusted_proxies" => ["10.0.0.0/8"]];
            $_SERVER = ["REMOTE_ADDR" => $argv[1], "HTTP_X_REQUEST_ID" => "lb-1234abcd"];
            echo versaWYS\kernel\Loggin::requestId();
        ');

        $fromProxy = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 10.0.0.5');
        $fromClient = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 203.0.113.8');
        unlink($script);

        self::assertSame('lb-1234abcd', $fromProxy);
        // Un cliente cualquiera no puede elegir el identificador con que quedan sus entradas
        self::assertNotSame('lb-1234abcd', $fromClient);
    }
}
