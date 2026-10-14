<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\commands\CheckDeployCommand;

final class ConfigSchemaTest extends TestCase
{
    public function testABooleanWrittenAsTextIsSerious(): void
    {
        // "false" en texto es verdadero para PHP: el debug quedaría activo
        $findings = self::findings(['build' => ['debug' => 'false']]);

        self::assertSame('serious', $findings[0][0]);
        self::assertStringContainsString('build.debug debe ser true o false', $findings[0][1]);
    }

    public function testATypoInASecuritySectionIsReported(): void
    {
        // "secrue" no existe: la cookie seguiría con el valor por defecto sin que nadie lo note
        $findings = self::findings(['session' => ['user_cookie' => ['secrue' => false]]]);

        self::assertCount(1, $findings);
        self::assertStringContainsString('session.user_cookie.secrue', $findings[0][1]);
    }

    public function testProjectSpecificTopLevelKeysAreAllowed(): void
    {
        self::assertSame([], self::findings(['webpay' => ['commerce_code' => 'x'], 'llm' => ['model' => 'y']]));
    }

    public function testAListWhereAnObjectIsExpectedIsSerious(): void
    {
        $findings = self::findings(['trusted_proxies' => '10.0.0.0/8']);

        self::assertSame('serious', $findings[0][0]);
        self::assertStringContainsString('trusted_proxies debe ser una lista', $findings[0][1]);
    }

    public function testKeysWithoutEffectAreGroupedInOneFinding(): void
    {
        $findings = self::findings([
            'build' => ['cacheRoute' => true],
            'api' => ['auth' => true],
            'rate_limiting' => ['storage' => 'redis'],
        ]);

        self::assertCount(1, $findings);
        self::assertStringContainsString('build.cacheRoute', $findings[0][1]);
        self::assertStringContainsString('api.auth', $findings[0][1]);
        self::assertStringContainsString('rate_limiting.storage', $findings[0][1]);
    }

    public function testEveryKeyWithoutEffectIsEitherADefaultOrARemovedFeature(): void
    {
        // Si alguien vuelve a leer una de estas claves, debe sacarla de la lista
        $kernel = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src')) as $file) {
            if (
                $file->getExtension() === 'php'
                && !str_ends_with($file->getPathname(), 'config.default.php')
                && !str_ends_with($file->getPathname(), 'CheckDeployCommand.php')
            ) {
                $kernel .= file_get_contents($file->getPathname());
            }
        }

        // Lecturas, no los unset() que limpian la clave
        $reads = implode("\n", array_filter(
            explode("\n", $kernel),
            static fn(string $line): bool => !str_contains($line, 'unset('),
        ));

        foreach (array_keys(CheckDeployCommand::DEAD_KEYS) as $dead) {
            // Los dos últimos segmentos: ['api']['auth'], no cualquier ['auth']
            $access = implode('', array_map(
                static fn(string $part): string => "['{$part}']",
                array_slice(explode('.', $dead), -2),
            ));
            self::assertStringNotContainsString(
                $access,
                $reads,
                "{$dead} figura como sin efecto pero el kernel la lee",
            );
        }
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> */
    private static function findings(array $config): array
    {
        return CheckDeployCommand::schemaFindings($config, require dirname(__DIR__) . '/src/config/config.default.php');
    }
}
