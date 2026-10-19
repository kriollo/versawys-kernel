<?php

declare(strict_types=1);

namespace versaWYS\kernel;

/**
 * La empresa del usuario en sesión, para aislar los datos de cada una. Lo que hacía falta escribir en cada
 * controlador (buscar el registro, comparar su empresa, responder 404) queda en una línea:
 *
 *     $tablero = $modelo->buscar($id);
 *     Tenant::ensure($tablero);          // 404 si no existe o es de otra empresa
 */
final class Tenant
{
    /** La empresa seleccionada por el usuario en sesión; 0 sin sesión. */
    public static function id(): int
    {
        return (int) (GlobalMiddleware::getLoadedUserData()['empresaSelected']['id'] ?? 0);
    }

    /** @param array<string, mixed>|object $row */
    public static function owns(array|object $row, string $column = 'id_empresa'): bool
    {
        // Un modelo de VersaORM se lee con export(); otro objeto, por sus propiedades públicas
        $data = match (true) {
            is_array($row) => $row,
            method_exists($row, 'export') => (array) $row->export(),
            default => get_object_vars($row),
        };
        $value = $data[$column] ?? null;

        return $value !== null && self::id() !== 0 && (int) $value === self::id();
    }

    /**
     * Si el registro no existe o es de otra empresa responde 404 y termina la petición. 404 y no 403: a
     * otra empresa no se le confirma que el registro existe.
     *
     * @param array<string, mixed>|object|null $row
     */
    public static function ensure(array|object|null $row, string $column = 'id_empresa'): void
    {
        if ($row !== null && self::owns($row, $column)) {
            return;
        }

        // El Router responde el 404: página o JSON, según la petición
        throw new NotFound();
    }
}
