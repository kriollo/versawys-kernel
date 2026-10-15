<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\ConfigManager;

/** config:align cambia la forma de config.json, nunca cómo se comporta la aplicación. */
final class ConfigAlignTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/versawys-align-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        putenv('VERSAWYS_CONFIG=' . $this->dir . '/config.json');
    }

    protected function tearDown(): void
    {
        putenv('VERSAWYS_CONFIG');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testKeepsBehaviorProjectKeysAndRemovesObsoleteOnes(): void
    {
        $clave = bin2hex(random_bytes(8));
        $antes = [
            'database' => ['driver' => 'postgresql', 'database' => 'app', 'password' => $clave],
            'build' => ['debug' => false, 'cacheRoute' => true],
            'security' => ['sanitize_input' => true],
            'csp' => ['enabled' => true, 'script-src' => ['https://accounts.google.com']],
            'permissions_policy' => ['camera' => []],
            'websocket_service' => ['ws_url' => 'wss://x', 'secret' => $clave],
            'llm' => ['timeout_seconds' => 30],
        ];
        file_put_contents($this->dir . '/config.json', json_encode($antes));

        ob_start();
        ConfigManager::align();
        ob_end_clean();
        $despues = json_decode((string) file_get_contents($this->dir . '/config.json'), true);

        // Lo que ve la aplicación (config.php mezcla así los valores por defecto) no cambia, salvo lo obsoleto
        $efectiva = static fn(array $c): array => array_replace_recursive(ConfigManager::defaults(), $c);
        $esperada = $efectiva($antes);
        unset($esperada['build']['cacheRoute'], $esperada['security']);
        $obtenida = $efectiva($despues);
        unset($obtenida['security']);
        self::assertEquals($esperada, $obtenida);

        // Valores, secretos y claves propias del proyecto intactos
        self::assertSame($clave, $despues['database']['password']);
        self::assertSame(['https://accounts.google.com'], $despues['csp']['script-src']);
        self::assertSame(['camera' => []], $despues['permissions_policy']);
        self::assertSame($clave, $despues['websocket_service']['secret']);
        self::assertSame(['timeout_seconds' => 30], $despues['llm']);

        // Lo obsoleto se fue y lo que faltaba quedó escrito, en el orden de config.default.php
        self::assertArrayNotHasKey('cacheRoute', $despues['build']);
        self::assertArrayNotHasKey('security', $despues);
        self::assertArrayHasKey('trusted_proxies', $despues);
        self::assertSame(array_keys(ConfigManager::defaults())[0], array_key_first($despues));

        // Copia del original, solo para el dueño
        $copias = glob($this->dir . '/config.json.*.bak');
        self::assertCount(1, $copias);
        self::assertSame($antes, json_decode((string) file_get_contents($copias[0]), true));
        self::assertSame(0o600, fileperms($copias[0]) & 0o777);
    }

    public function testDryRunDoesNotWrite(): void
    {
        file_put_contents($this->dir . '/config.json', '{"build":{"cacheRoute":true}}');
        $argv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['versaCLI', 'config:align', '--dry-run'];

        try {
            ob_start();
            ConfigManager::align();
            ob_end_clean();
        } finally {
            $_SERVER['argv'] = $argv;
        }

        self::assertSame('{"build":{"cacheRoute":true}}', file_get_contents($this->dir . '/config.json'));
        self::assertSame([], glob($this->dir . '/config.json.*.bak'));
    }
}
