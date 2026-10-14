<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Request;

final class RequestProxyTest extends TestCase
{
    private array $originalServer;

    private mixed $originalConfig;

    private array $originalGet;

    private array $originalRequest;

    private array $originalFiles;

    protected function setUp(): void
    {
        global $config;

        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
        $this->originalRequest = $_REQUEST;
        $this->originalFiles = $_FILES;
        $this->originalConfig = $config ?? null;
        $config = ['trusted_proxies' => []];
        $_SERVER = [];
        $_GET = [];
        $_REQUEST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        global $config;

        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
        $_REQUEST = $this->originalRequest;
        $_FILES = $this->originalFiles;
        $config = $this->originalConfig;
    }

    public function testItMatchesIPv4AndIPv6Networks(): void
    {
        global $config;
        $config['trusted_proxies'] = ['192.0.2.0/24', '2001:db8::/32'];

        self::assertTrue(Request::isTrustedProxy('192.0.2.128'));
        self::assertFalse(Request::isTrustedProxy('192.0.3.1'));
        self::assertTrue(Request::isTrustedProxy('2001:db8:1::1'));
        self::assertFalse(Request::isTrustedProxy('2001:db9::1'));
    }

    public function testItSupportsZeroLengthIpv4AndIpv6Networks(): void
    {
        global $config;
        $config['trusted_proxies'] = ['0.0.0.0/0', '::/0'];

        self::assertTrue(Request::isTrustedProxy('203.0.113.8'));
        self::assertTrue(Request::isTrustedProxy('2001:db8::1'));
    }

    public function testItRejectsMalformedAndOutOfRangePrefixes(): void
    {
        global $config;
        $config['trusted_proxies'] = ['192.0.2.1/not-a-prefix', '192.0.2.1/33', '2001:db8::/129'];

        // Antes, "/not-a-prefix" valía como /0 y confiaba en todas las IPv4
        $log = tempnam(sys_get_temp_dir(), 'proxy');
        $previous = ini_set('error_log', $log);
        try {
            self::assertFalse(Request::isTrustedProxy('203.0.113.8'));
            self::assertFalse(Request::isTrustedProxy('192.0.2.1'));
            self::assertFalse(Request::isTrustedProxy('2001:db8::1'));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        self::assertStringContainsString('"192.0.2.1/not-a-prefix"', (string) file_get_contents($log));
        unlink($log);
    }

    public function testParseProxyRangeValidatesWithoutAnIpToCompare(): void
    {
        // app:check:deploy valida la configuración sin una IP de por medio
        self::assertSame(8, Request::parseProxyRange('10.0.0.0/8')[1] ?? null);
        self::assertSame(8, Request::parseProxyRange(' 10.0.0.0/8 ')[1] ?? null);
        self::assertSame(32, Request::parseProxyRange('10.0.0.1')[1] ?? null);
        self::assertSame(128, Request::parseProxyRange('2001:db8::1')[1] ?? null);
        self::assertNull(Request::parseProxyRange('10.0.0.0/33'));
        self::assertNull(Request::parseProxyRange('10.0.0.0/abc'));
        self::assertNull(Request::parseProxyRange('10.0.0.0/-1'));
        self::assertNull(Request::parseProxyRange('2001:db8::/129'));
        self::assertNull(Request::parseProxyRange('proxy.local'));
    }

    public function testItUsesForwardedClientIpOnlyFromTrustedProxies(): void
    {
        global $config;
        $config['trusted_proxies'] = ['10.0.0.0/8'];
        $_SERVER = [
            'REMOTE_ADDR' => '10.0.0.8',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20, 10.0.0.7',
        ];

        self::assertSame('198.51.100.20', Request::clientIp());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
        self::assertSame('203.0.113.8', Request::clientIp());
    }

    public function testTheConfiguredBaseUrlWinsOverTheHostHeader(): void
    {
        global $config;
        $config['build'] = ['base_url' => 'https://app.ejemplo.cl/'];
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'atacante.test', 'REMOTE_ADDR' => '203.0.113.8'];

        self::assertSame('https://app.ejemplo.cl', (new Request())->getBaseUrl());
    }

    public function testForwardedHostFromAnUntrustedClientIsIgnored(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'app.ejemplo.cl',
            'HTTP_X_FORWARDED_HOST' => 'atacante.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '203.0.113.8',
        ];

        self::assertSame('http://app.ejemplo.cl', (new Request())->getBaseUrl());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function hosts(): iterable
    {
        yield 'dominio' => ['app.ejemplo.cl', 'http://app.ejemplo.cl'];
        yield 'con puerto' => ['localhost:8000', 'http://localhost:8000'];
        yield 'IPv6' => ['[::1]:8080', 'http://[::1]:8080'];
        yield 'con comillas' => ['evil.test"><img src=x>', 'http://localhost'];
        yield 'con ruta' => ['evil.test/reset?x=', 'http://localhost'];
        yield 'con usuario' => ['app.ejemplo.cl@evil.test', 'http://localhost'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hosts')]
    public function testTheHostHeaderMustBeAHostName(string $host, string $expected): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => $host, 'REMOTE_ADDR' => '203.0.113.8'];

        self::assertSame($expected, (new Request())->getBaseUrl());
    }

    public function testRequestDefaultsToGetAndPreservesSubmittedValues(): void
    {
        $_GET = ['query' => '<script>alert(1)</script>'];
        $_REQUEST = $_GET;

        $request = new Request();

        self::assertSame('GET', $request->getMethod());
        self::assertSame($_GET, $request->getAllParams());
    }
}
