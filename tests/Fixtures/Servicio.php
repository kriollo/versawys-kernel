<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

final class Servicio
{
    public function __construct(
        public readonly Repositorio $repo,
        public readonly int $limite = 10,
    ) {}
}
