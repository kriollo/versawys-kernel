<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

final class ControladorCosas
{
    public static int $llamadas = 0;

    public function guardar(GuardarCosa $datos): array
    {
        self::$llamadas++;

        return $datos->validated();
    }
}
