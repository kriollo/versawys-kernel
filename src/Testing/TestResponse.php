<?php

declare(strict_types=1);

namespace versaWYS\kernel\Testing;

use PHPUnit\Framework\Assert;

/** Respuesta de TestClient, con aserciones encadenables. */
final class TestResponse
{
    /** @param array<string, list<string>> $headers nombres en minúsculas */
    public function __construct(
        public readonly int $status,
        private readonly array $headers,
        public readonly string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** El cuerpo como JSON; con $path (`data.0.id`), solo ese valor. */
    public function json(?string $path = null): mixed
    {
        $value = json_decode($this->body, true);
        foreach ($path === null ? [] : explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status, 'Estado HTTP inesperado. Cuerpo: ' . mb_substr($this->body, 0, 500));

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertRedirect(?string $to = null): self
    {
        Assert::assertContains($this->status, [301, 302, 303, 307, 308], 'No es una redirección: ' . $this->status);
        if ($to !== null) {
            Assert::assertSame($to, $this->header('Location'));
        }

        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): self
    {
        Assert::assertNotNull($this->header($name), "Falta la cabecera {$name}");
        if ($value !== null) {
            Assert::assertSame($value, $this->header($name));
        }

        return $this;
    }

    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->body);

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->body);

        return $this;
    }

    /**
     * El JSON contiene estos pares (puede tener más).
     *
     * @param array<string, mixed> $subset
     */
    public function assertJson(array $subset): self
    {
        $json = $this->json();
        Assert::assertIsArray($json, 'La respuesta no es JSON: ' . mb_substr($this->body, 0, 300));
        foreach ($subset as $key => $expected) {
            Assert::assertArrayHasKey($key, $json);
            Assert::assertEquals($expected, $json[$key], "JSON[{$key}]");
        }

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertEquals($expected, $this->json($path), "JSON en {$path}");

        return $this;
    }
}
