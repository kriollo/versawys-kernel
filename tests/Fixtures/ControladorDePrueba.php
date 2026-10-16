<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

final class ControladorDePrueba
{
    public function ver(Servicio $servicio, int $id): string
    {
        return $servicio->repo->reloj->ahora() . '#' . $id;
    }

    public function texto(string $slug, ?string $extra = null): string
    {
        return $slug . '|' . ($extra ?? '-');
    }

    public function sinParametros(): int
    {
        return func_num_args();
    }
}
