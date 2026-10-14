<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Router;

final class HealthTest extends TestCase
{
    private array $originalServer;

    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalServer = $_SERVER;
        $this->originalConfig = $config ?? null;
        $config = ['session' => ['key' => str_repeat('k', 64)]];
    }

    protected function tearDown(): void
    {
        global $config;

        $_SERVER = $this->originalServer;
        $config = $this->originalConfig;
    }

    public function testLoopbackWithoutTheSignedHeaderOnlyGetsStatus(): void
    {
        // Detrás de un proxy en el mismo servidor, cualquier visita llega desde 127.0.0.1
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'];

        self::assertSame(['status' => 'ok'], Router::health());
    }

    public function testTheSignedHeaderGetsTheRuntimeDetails(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '203.0.113.8', 'HTTP_X_VERSAWYS_HEALTH' => Router::healthToken()];

        self::assertArrayHasKey('opcache', Router::health());
        self::assertArrayHasKey('php', Router::health());
    }

    public function testAWrongSignatureOnlyGetsStatus(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_VERSAWYS_HEALTH' => str_repeat('0', 64)];

        self::assertSame(['status' => 'ok'], Router::health());
    }

    public function testWithoutSessionKeyThereIsNoValidSignature(): void
    {
        global $config;
        $config = [];
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_VERSAWYS_HEALTH' => ''];

        self::assertSame('', Router::healthToken());
        self::assertSame(['status' => 'ok'], Router::health());
    }
}
