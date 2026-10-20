<?php

declare(strict_types=1);

namespace versaWYS\kernel\Testing;

use Closure;
use PDO;
use RuntimeException;

/**
 * Datos de prueba en una línea, sobre la base de TestDatabase.
 *
 *     // versaWYS/tests/factories/OrdenesCompra.php (lo crea factory:make o versaMODULE)
 *     Factory::define('ordenes_compra', static fn(int $n): array => ['nombre' => "Orden {$n}", 'id_empresa' => 1]);
 *
 *     $id = Factory::create('ordenes_compra', ['id_empresa' => $empresa]);
 *     $ids = Factory::createMany('ordenes_compra', 10, ['id_empresa' => $empresa]);
 *
 * Las definiciones de versaWYS/tests/factories se cargan solas. Si la tabla tiene created_at y
 * updated_at y no vienen, se completan con la fecha actual.
 */
final class Factory
{
    /** @var array<string, Closure(int): array<string, mixed>> */
    private static array $definitions = [];

    private static int $sequence = 0;

    private static bool $loaded = false;

    /**
     * @param Closure(int): array<string, mixed> $defaults recibe un número que no se repite, para valores únicos
     */
    public static function define(string $table, Closure $defaults): void
    {
        self::$definitions[$table] = $defaults;
    }

    /**
     * Los datos de un registro, sin guardarlo.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function make(string $table, array $overrides = []): array
    {
        self::load();
        $defaults = self::$definitions[$table] ?? null;
        if ($defaults === null) {
            throw new RuntimeException(
                "No hay factory para «{$table}»: créala con php versaCLI factory:make:<Nombre> (versaWYS/tests/factories).",
            );
        }

        return $overrides + $defaults(++self::$sequence);
    }

    /**
     * Guarda un registro y devuelve su id.
     *
     * @param array<string, mixed> $overrides
     */
    public static function create(string $table, array $overrides = []): int
    {
        $row = self::make($table, $overrides);
        // Tabla y columnas van dentro del SQL: solo identificadores simples
        foreach ([$table, ...array_map(strval(...), array_keys($row))] as $identifier) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
                throw new RuntimeException("Nombre de tabla o columna no válido: {$identifier}");
            }
        }

        $columns = self::columns($table);
        foreach (['created_at', 'updated_at'] as $timestamp) {
            if (!in_array($timestamp, $columns, true) || array_key_exists($timestamp, $row)) {
                continue;
            }

            $row[$timestamp] = date('Y-m-d H:i:s');
        }

        $pdo = TestDatabase::pdo();
        $names = array_map(strval(...), array_keys($row));

        $pdo->prepare(sprintf(
            'INSERT INTO "%s" ("%s") VALUES (%s)',
            $table,
            implode('", "', $names),
            implode(', ', array_fill(0, count($names), '?')),
        ))->execute(array_values($row));

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides los mismos para todos
     *
     * @return list<int>
     */
    public static function createMany(string $table, int $count, array $overrides = []): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = self::create($table, $overrides);
        }

        return $ids;
    }

    /** @internal Solo para pruebas del kernel. */
    public static function reset(): void
    {
        self::$definitions = [];
        self::$sequence = 0;
        self::$loaded = false;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;
        $files = glob(getcwd() . '/versaWYS/tests/factories/*.php');
        foreach ($files === false ? [] : $files as $file) {
            require_once $file;
        }
    }

    /** @return list<string> */
    private static function columns(string $table): array
    {
        $pdo = TestDatabase::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $statement = $pdo->prepare(
                "SELECT column_name AS name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?",
            );
            $statement->execute([$table]);
        } else {
            $statement = $pdo->query(sprintf('PRAGMA table_info("%s")', $table));
        }

        $rows = $statement === false ? [] : $statement->fetchAll();

        return array_values(array_map(static fn(array $c): string => (string) $c['name'], $rows));
    }
}
