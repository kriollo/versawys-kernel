<?php

declare(strict_types=1);

namespace app\models;

use versaWYS\kernel\model\VersaORMModel;

/** Doble del modelo del panel para CacheUsersTest */
class Empresas extends VersaORMModel
{
    protected string $table = 'empresas';

    /** @return list<array<string, mixed>> */
    public function all(string $estado = 'all'): array
    {
        return self::getAll('SELECT * FROM empresas WHERE estado = ? ORDER BY id', [$estado]);
    }

    /** @return array<string, mixed>|null */
    public function getEmpresaById(int $id): ?array
    {
        return self::getRow('SELECT * FROM empresas WHERE id = ?', [$id]);
    }
}
