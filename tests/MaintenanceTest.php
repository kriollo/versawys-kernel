<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Maintenance;
use versaWYS\kernel\Output;
use versaWYS\kernel\Request;

final class MaintenanceTest extends TestCase
{
    private string $dir;

    private mixed $originalConfig = null;

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $this->server = $_SERVER;
        $config = ['session' => ['key' => str_repeat('k', 64), 'user_cookie' => ['secure' => false]]];
        $this->dir = sys_get_temp_dir() . '/versawys_down_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        putenv('VERSAWYS_CONFIG=' . $this->dir . '/config.json');
    }

    protected function tearDown(): void
    {
        global $config;

        $config = $this->originalConfig;
        $_SERVER = $this->server;
        $_COOKIE = [];
        putenv('VERSAWYS_CONFIG');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testSinMantenimientoLaPeticionSigue(): void
    {
        self::assertNull(Maintenance::status());
        self::assertFalse($this->request('/admin')[0]);
    }

    public function testEnMantenimientoRespondeUn503ConRetryAfter(): void
    {
        Maintenance::down('Volvemos a las 10', 120);

        [$handled, $body, $out] = $this->request('/admin');
        self::assertTrue($handled);
        self::assertSame(503, $out['status']);
        self::assertStringContainsString('120', implode(' ', $out['headers']['retry-after'] ?? []));
        self::assertStringContainsString('Volvemos a las 10', $body);

        [, $body, $out] = $this->request('/api/datos', ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(503, $out['status']);
        self::assertTrue(json_decode($body, true)['maintenance'] ?? false, 'la API responde en JSON');
    }

    public function testLaClaveDejaEntrarAlEquipo(): void
    {
        $state = Maintenance::down('', 60, 'equipo-123');

        [$handled, , $out] = $this->request('/equipo-123');
        self::assertTrue($handled);
        $cookie = $out['cookies'][Maintenance::COOKIE]['value'] ?? '';
        self::assertNotSame('', $cookie);
        self::assertNotSame($state['secret'], $cookie, 'la cookie no es la clave');

        $_COOKIE[Maintenance::COOKIE] = $cookie;
        self::assertFalse($this->request('/admin')[0], 'con la cookie entra');

        $_COOKIE[Maintenance::COOKIE] = 'falsa';
        self::assertTrue($this->request('/admin')[0], 'una cookie inventada no sirve');
    }

    public function testUpLoTermina(): void
    {
        Maintenance::down();
        self::assertTrue(Maintenance::up());
        self::assertFalse($this->request('/admin')[0]);
        self::assertFalse(Maintenance::up(), 'ya no estaba en mantenimiento');
    }

    /** @return array{0: bool, 1: string, 2: array{status: int, headers: array<string, list<string>>, cookies: array<string, array<string, mixed>>}} */
    private function request(string $url, array $server = []): array
    {
        $_SERVER = ['REQUEST_URI' => $url, 'REQUEST_METHOD' => 'GET'] + $server;
        Output::capture();
        ob_start();
        $handled = Maintenance::handle(new Request(), $url);

        return [$handled, (string) ob_get_clean(), Output::release()];
    }
}
