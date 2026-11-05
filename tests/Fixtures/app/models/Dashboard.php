<?php

declare(strict_types=1);

namespace app\models;

use versaWYS\kernel\model\VersaORMModel;

/** Doble del modelo del panel para CacheUsersTest: el menú no se lee de la base */
class Dashboard extends VersaORMModel
{
    protected string $table = 'versa_users';

    /** @return list<string> */
    public function getMenuAdmin(): array
    {
        return ['menu-admin'];
    }

    /** @return list<string> */
    public function getMenuUser(int $idUser, int $idPerfil = 0): array
    {
        return ['menu-' . $idUser . '-' . $idPerfil];
    }
}
