<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\commands\KernelPublishCommand;

final class KernelPublishTablasTest extends TestCase
{
    public function testLasTablasComunesExistenYNoSonArchivosBase(): void
    {
        $tablas = KernelPublishCommand::tablas();

        self::assertSame(
            ['src/dashboard/js/components/customTable.vue', 'src/dashboard/js/components/customTableSimple.vue'],
            array_keys($tablas),
        );
        foreach ($tablas as $destino => $origen) {
            self::assertFileExists($origen);
            self::assertArrayNotHasKey($destino, KernelPublishCommand::stubs());
        }
    }
}
