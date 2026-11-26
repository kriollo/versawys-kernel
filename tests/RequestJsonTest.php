<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Request;

/** El cuerpo JSON se mide antes de decodificarse y se decodifica una sola vez, con anidación acotada. */
final class RequestJsonTest extends TestCase
{
    private array $originalServer;

    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalServer = $_SERVER;
        $this->originalConfig = $config ?? null;
        $config = ['trusted_proxies' => [], 'max_json_bytes' => 1024];
    }

    protected function tearDown(): void
    {
        global $config;

        $_SERVER = $this->originalServer;
        $config = $this->originalConfig;
        Request::setInput(null);
        Request::resetRequestState();
    }

    private function request(string $method, string $body): Request
    {
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '/x',
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        Request::setInput($body);

        return new Request();
    }

    public function testDecodesAValidBodyInAnyMethod(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $r = $this->request($method, '{"nombre":"Ana","items":[1,2]}');
            self::assertNull($r->bodyError(), $method);
            self::assertSame('Ana', $r->get('nombre'), $method);
        }

        self::assertNull($this->request('POST', '')->bodyError());
    }

    public function testRejectsABodyOverTheLimitWithoutDecodingIt(): void
    {
        $r = $this->request('POST', '{"x":"' . str_repeat('a', 2000) . '"}');
        self::assertSame(413, $r->bodyError()['code'] ?? null);
        self::assertNull($r->get('x'));

        // 0 desactiva el tope
        global $config;
        $config['max_json_bytes'] = 0;
        self::assertNull($this->request('POST', '{"x":"' . str_repeat('a', 2000) . '"}')->bodyError());
    }

    public function testRejectsInvalidOrTooDeepJson(): void
    {
        self::assertSame(400, $this->request('POST', '{"x":')->bodyError()['code'] ?? null);
        self::assertSame(400, $this->request('PUT', '{"x":')->bodyError()['code'] ?? null);

        $profundo = str_repeat('[', Request::JSON_MAX_DEPTH + 1) . str_repeat(']', Request::JSON_MAX_DEPTH + 1);
        self::assertSame(400, $this->request('POST', $profundo)->bodyError()['code'] ?? null);
    }
}
