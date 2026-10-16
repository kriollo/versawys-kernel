<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

final class Repositorio
{
    public function __construct(
        public readonly Reloj $reloj,
    ) {}
}
