<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

use Override;
use RuntimeException;
use versaWYS\kernel\Job;

final class TrabajoDePrueba implements Job
{
    /** @var list<array<array-key, mixed>> */
    public static array $hechos = [];

    public static int $fallarVeces = 0;

    #[Override]
    public function handle(array $data): void
    {
        if (self::$fallarVeces > 0) {
            self::$fallarVeces--;

            throw new RuntimeException('servicio caído');
        }

        self::$hechos[] = $data;
    }
}
