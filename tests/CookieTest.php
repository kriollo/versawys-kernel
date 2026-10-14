<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Cookie;

final class CookieTest extends TestCase
{
    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
    }

    protected function tearDown(): void
    {
        global $config;

        $config = $this->originalConfig;
    }

    public function testACookieWithoutAnExplicitFlagFollowsTheSessionCookie(): void
    {
        global $config;

        // Sin configuración: segura por defecto
        $config = [];
        self::assertTrue(Cookie::defaultSecure());

        // Desarrollo local por HTTP
        $config = ['session' => ['user_cookie' => ['secure' => false]]];
        self::assertFalse(Cookie::defaultSecure());
    }
}
