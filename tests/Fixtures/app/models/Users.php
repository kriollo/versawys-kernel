<?php

declare(strict_types=1);

namespace app\models;

use versaWYS\kernel\model\VersaORMModel;

/** Doble del modelo del panel para CacheUsersTest: la misma consulta, y cuenta las cargas */
class Users extends VersaORMModel
{
    public static int $loads = 0;

    protected string $table = 'versa_users';

    /** @return array<string, mixed> */
    public function findWithCompany(int|string $id): array
    {
        self::$loads++;

        return self::getRow('SELECT versa_users.*, e.nombre AS empresa FROM versa_users JOIN empresas e ON e.id = versa_users.id_empresa
             WHERE versa_users.id = ?', [$id]) ?? [];
    }
}
