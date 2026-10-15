<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Closure;

/**
 * Permisos por habilidad, definidos en un solo lugar (p. ej. app/container.php):
 *
 *     Gate::before(static fn(array $u): ?bool => $u['role'] === 'superadmin' ? true : null);
 *     Gate::define('tableros.editar', static fn(array $u, array $tablero): bool => ...);
 *     Gate::define('facturas.anular', [FacturaPolicy::class, 'anular']);   // clase resuelta con el contenedor
 *
 *     if (Gate::denies('tableros.editar', $tablero)) { ... }
 *     Gate::authorize('facturas.anular', $factura);   // si no puede: 403 y termina la petición
 *
 * Sin usuario en sesión o con una habilidad no definida, la respuesta es no.
 */
final class Gate
{
    /** @var array<string, array{0: class-string, 1: string}|Closure> */
    private static array $abilities = [];

    /** @var list<Closure> */
    private static array $before = [];

    /** @param array{0: class-string, 1: string}|Closure $callback recibe (usuario, ...argumentos) */
    public static function define(string $ability, Closure|array $callback): void
    {
        self::$abilities[$ability] = $callback;
    }

    /** Se consulta antes que cualquier habilidad: true o false decide; null sigue con la habilidad. */
    public static function before(Closure $callback): void
    {
        self::$before[] = $callback;
    }

    public static function allows(string $ability, mixed ...$arguments): bool
    {
        $user = GlobalMiddleware::getLoadedUserData()['user'] ?? null;

        return is_array($user) && self::check($user, $ability, ...$arguments);
    }

    public static function denies(string $ability, mixed ...$arguments): bool
    {
        return !self::allows($ability, ...$arguments);
    }

    /** Si el usuario no puede, responde 403 y termina la petición. */
    public static function authorize(string $ability, mixed ...$arguments): void
    {
        if (self::allows($ability, ...$arguments)) {
            return;
        }

        Response::json([
            'success' => 0,
            'message' => 'No tiene permisos para realizar esta acción',
            'code' => 403,
        ], 403);
        Output::halt();
    }

    /**
     * La decisión para un usuario dado (lo que usan allows/authorize con el de la sesión).
     *
     * @param array<string, mixed> $user
     */
    public static function check(array $user, string $ability, mixed ...$arguments): bool
    {
        foreach (self::$before as $before) {
            $decision = $before($user, $ability, ...$arguments);
            if (is_bool($decision)) {
                return $decision;
            }
        }

        $callback = self::$abilities[$ability] ?? null;
        if ($callback === null) {
            Loggin::warning("Habilidad no definida: {$ability}", 'security');

            return false;
        }

        if (is_array($callback)) {
            [$class, $method] = $callback;
            $callback = [Container::getInstance()->get($class), $method];
            // Un método mal escrito en define() niega, igual que una habilidad no definida
            if (!is_callable($callback)) {
                Loggin::warning("Política sin el método {$class}::{$method} para {$ability}", 'security');

                return false;
            }
        }

        return $callback($user, ...$arguments) === true;
    }

    /** @internal Solo para pruebas. */
    public static function reset(): void
    {
        self::$abilities = [];
        self::$before = [];
    }
}
