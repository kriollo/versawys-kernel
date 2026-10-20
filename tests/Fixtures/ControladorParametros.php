<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

final class ControladorParametros
{
    public function cruzados(int $idEmpresa, int $kbId): void {}

    /** Otro nombre en la misma posición no es un problema; un servicio tipado no cuenta */
    public function ordenados(Reloj $reloj, int $id, string $slug): void {}
}
