<?php

declare(strict_types=1);

namespace versaWYS\kernel;

/**
 * Único punto por el que el kernel escribe la respuesta HTTP: cabeceras, estado, cookies y el fin de la petición.
 *
 * En producción hace lo mismo que header(), http_response_code(), setcookie() y exit(). En pruebas
 * (Testing\TestClient) lo registra todo y termina la petición con RequestHalted en vez de exit(), así la
 * aplicación se prueba en el mismo proceso, sin levantar un servidor.
 */
final class Output
{
    /** @var array{status: int, headers: array<string, list<string>>, cookies: array<string, array<string, mixed>>}|null */
    private static ?array $captured = null;

    /** @internal Lo usa Testing\TestClient al empezar cada petición. */
    public static function capture(): void
    {
        self::$captured = ['status' => 200, 'headers' => [], 'cookies' => []];
    }

    /**
     * @internal lo usa Testing\TestClient al terminar cada petición
     *
     * @return array{status: int, headers: array<string, list<string>>, cookies: array<string, array<string, mixed>>}
     */
    public static function release(): array
    {
        $captured = self::$captured ?? ['status' => 200, 'headers' => [], 'cookies' => []];
        self::$captured = null;

        return $captured;
    }

    public static function capturing(): bool
    {
        return self::$captured !== null;
    }

    public static function header(string $line, bool $replace = true): void
    {
        if (self::$captured === null) {
            header($line, $replace);

            return;
        }

        [$name, $value] = array_map(trim(...), explode(':', $line, 2)) + [1 => ''];
        $key = strtolower($name);
        if ($replace || !isset(self::$captured['headers'][$key])) {
            self::$captured['headers'][$key] = [$value];
        } else {
            self::$captured['headers'][$key][] = $value;
        }

        // Como header(): un Location sin estado propio es una redirección 302
        if ($key === 'location' && self::$captured['status'] === 200) {
            self::$captured['status'] = 302;
        }
    }

    public static function removeHeader(string $name): void
    {
        if (self::$captured === null) {
            header_remove($name);

            return;
        }

        unset(self::$captured['headers'][strtolower($name)]);
    }

    public static function status(int $code): void
    {
        if (self::$captured === null) {
            http_response_code($code);

            return;
        }

        self::$captured['status'] = $code;
    }

    /** @param array{expires?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: 'Lax'|'lax'|'None'|'none'|'Strict'|'strict'} $options */
    public static function cookie(string $name, string $value, array $options): void
    {
        if (self::$captured === null) {
            setcookie($name, $value, $options);

            return;
        }

        self::$captured['cookies'][$name] = ['value' => $value] + $options;
    }

    /** Termina la petición: exit() en producción; en pruebas, RequestHalted, que atrapa el cliente. */
    public static function halt(int $exitCode = 0): never
    {
        if (self::$captured !== null) {
            throw new RequestHalted();
        }

        exit($exitCode);
    }
}
