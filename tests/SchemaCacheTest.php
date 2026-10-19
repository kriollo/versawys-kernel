<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use stdClass;
use VersaORM\SQL\PdoConnection;
use VersaORM\VersaORM;
use versaWYS\kernel\model\OrmFactory;

/** En producción la introspección de esquema del ORM se guarda en Cache entre peticiones. */
final class SchemaCacheTest extends TestCase
{
    private string $dir;

    private mixed $originalConfig = null;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $this->dir = sys_get_temp_dir() . '/versawys-schema-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $config = ['build' => ['debug' => false], 'cache' => ['dir' => $this->dir . '/cache']];
    }

    protected function tearDown(): void
    {
        global $config;

        VersaORM::schemaCache(null);
        VersaORM::onQuery(null);
        PdoConnection::clearPool();
        $config = $this->originalConfig;
        foreach (glob($this->dir . '/{,cache/}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        @rmdir($this->dir . '/cache'); // @mago-expect lint:no-error-control-operator
        rmdir($this->dir);
    }

    public function testLaSiguientePeticionNoConsultaElEsquemaYUnDdlLoInvalida(): void
    {
        $orm = $this->peticion(debug: false);
        $orm->exec('CREATE TABLE productos (id INTEGER PRIMARY KEY, nombre TEXT)');
        $orm->schema('columns', 'productos');

        $consultas = $this->contarConsultas();
        $orm = $this->peticion(debug: false, anterior: $orm);
        self::assertSame(['id', 'nombre'], array_column($orm->schema('columns', 'productos'), 'name'));
        self::assertSame(0, $consultas->n, 'la segunda petición usa Cache, no la base');

        // Una migración (DDL por el ORM) invalida lo guardado
        $orm->exec('ALTER TABLE productos ADD COLUMN precio INTEGER');
        $orm = $this->peticion(debug: false, anterior: $orm);
        self::assertContains('precio', array_column($orm->schema('columns', 'productos'), 'name'));
    }

    public function testEnDebugNoGuardaNada(): void
    {
        $orm = $this->peticion(debug: true);
        $orm->exec('CREATE TABLE productos (id INTEGER PRIMARY KEY)');
        $orm->schema('columns', 'productos');

        self::assertSame([], glob($this->dir . '/cache/*.json') ?: []);
    }

    /** Una petición nueva: otra conexión, sin la memoria de la anterior */
    private function peticion(bool $debug, ?VersaORM $anterior = null): VersaORM
    {
        global $config;

        $anterior?->disconnect();
        PdoConnection::clearPool();
        $config['build']['debug'] = $debug;

        return OrmFactory::make([
            'build' => ['debug' => $debug, 'timezone' => 'America/Santiago'],
            'database' => [
                'engine' => 'pdo',
                'driver' => 'sqlite',
                'database' => $this->dir . '/app.sqlite',
                'host' => '',
                'port' => 0,
                'charset' => 'utf8',
                'collation' => '',
                'username' => '',
                'password' => '',
            ],
        ]);
    }

    private function contarConsultas(): stdClass
    {
        $contador = new stdClass();
        $contador->n = 0;
        VersaORM::onQuery(static function () use ($contador): void {
            $contador->n++;
        });

        return $contador;
    }
}
