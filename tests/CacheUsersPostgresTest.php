<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PDO;

/**
 * Los casos de CacheUsersTest en PostgreSQL, donde `estado` es boolean de verdad. Necesita una base
 * desechable en VERSA_TEST_PG (`host:puerto:base:usuario:clave`, la que ya usa la CI), cuyo esquema `public`
 * se vacía; sin ella se salta.
 */
final class CacheUsersPostgresTest extends CacheUsersTest
{
    protected function setUp(): void
    {
        if ((string) getenv('VERSA_TEST_PG') === '') {
            self::markTestSkipped('Sin VERSA_TEST_PG (PostgreSQL desechable)');
        }

        parent::setUp();
    }

    protected function database(): array
    {
        [$host, $port, $database, $username, $password] = explode(':', (string) getenv('VERSA_TEST_PG'), 5)
        + ['', '', '', '', ''];

        return [
            'engine' => 'pdo',
            'driver' => 'postgresql',
            'host' => $host,
            'port' => (int) $port,
            'database' => $database,
            'log_path' => $this->dir() . '/orm/',
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8',
            'collation' => '',
        ];
    }

    protected function connect(array $database): PDO
    {
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $database['host'], $database['port'], $database['database']),
            $database['username'],
            $database['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        // Base desechable, como en TestDatabase: se parte de un esquema vacío
        $pdo->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public');

        return $pdo;
    }
}
