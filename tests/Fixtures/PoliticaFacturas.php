<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

final class PoliticaFacturas
{
    /** @param array<string, mixed> $usuario */
    public function anular(array $usuario, array $factura): bool
    {
        return $factura['autor'] === $usuario['id'];
    }
}
