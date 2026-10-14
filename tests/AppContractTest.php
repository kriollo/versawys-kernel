<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use versaWYS\kernel\cli\commands\CheckDeployCommand;

/**
 * El contrato kernel ↔ app: las clases de app/ que el kernel usa. Ampliarlo es una versión mayor,
 * así que no puede crecer sin que lo vean el código, la revisión de despliegue y UPGRADE.md.
 */
final class AppContractTest extends TestCase
{
    public function testTheKernelOnlyImportsAppClassesListedInTheContract(): void
    {
        $imported = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                '/^use (app\\\\(?:models|services|controllers|middleware)\\\\\w+);/m',
                (string) file_get_contents($file->getPathname()),
                $m,
            );
            foreach ($m[1] as $class) {
                $imported[$class] = true;
            }
        }

        self::assertSame(
            [],
            array_values(array_diff(array_keys($imported), array_keys(CheckDeployCommand::APP_CONTRACT))),
        );
    }

    public function testUpgradeGuideListsExactlyTheContract(): void
    {
        $guide = (string) file_get_contents(__DIR__ . '/../UPGRADE.md');
        preg_match_all('/^\| `(app\\\\[^`]+)` \| (.+) \|$/m', $guide, $rows, PREG_SET_ORDER);

        $documented = [];
        foreach ($rows as [, $class, $methods]) {
            preg_match_all('/`(\w+)`/', $methods, $names);
            $documented[$class] = $names[1];
        }

        self::assertSame(CheckDeployCommand::APP_CONTRACT, $documented);
    }
}
