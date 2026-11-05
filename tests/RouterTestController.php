<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use RuntimeException;

/** Controlador de las rutas que registra RouterTest */
final class RouterTestController
{
    public function show(): string
    {
        return 'ok';
    }

    /** @return array<string, mixed> */
    public function unauthorized(): array
    {
        return ['success' => 0, 'message' => 'No autenticado.', 'code' => 401];
    }

    /** @return array<string, mixed> */
    public function pdoCode(): array
    {
        return ['success' => 0, 'message' => 'Error', 'code' => 23_000];
    }

    public function echo(string $id): string
    {
        return 'id=' . $id;
    }

    public function explode(): never
    {
        throw new RuntimeException(
            'PDO engine execution error: SQL failed (get): SELECT password FROM versa_users en /var/www/app/models/Users.php:40',
        );
    }
}
