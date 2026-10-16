<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

use Override;

final class RelojFijo implements Reloj
{
    #[Override]
    public function ahora(): string
    {
        return '2026-10-15';
    }
}
