<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use VersaORM\SQL\PdoConnection;
use VersaORM\VersaORMException;
use versaWYS\kernel\ErrorHandler;
use versaWYS\kernel\model\OrmFactory;

/** Crear el ORM no conecta: la zona de la sesión de BD se fija al abrir la conexión, en la primera consulta */
final class OrmFactoryTest extends TestCase
{
    public function testSinConsultasNoSeConectaYElErrorDeConexionTraeLaAyuda(): void
    {
        PdoConnection::clearPool();
        // Puerto cerrado: si make() conectara (como hacía el SET TIME ZONE), fallaría aquí
        $database = [
            'engine' => 'pdo',
            'driver' => 'postgresql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'no_importa',
            'charset' => 'utf8',
            'collation' => '',
            'username' => 'nadie',
            'password' => '',
            // El error de conexión se registra: fuera del proyecto
            'log_path' => sys_get_temp_dir() . '/versawys_ormfactory_test/',
        ];
        $orm = OrmFactory::make([
            // Con debug no se registra la caché de esquema global, que quedaría para otras pruebas
            'build' => ['debug' => true, 'timezone' => 'America/Santiago'],
            'database' => $database,
        ]);

        try {
            $orm->exec('SELECT 1');
            self::fail('La primera consulta debía intentar conectar');
        } catch (VersaORMException $e) {
            self::assertTrue(ErrorHandler::isConnectionError($e));
            self::assertStringContainsString(
                'ERROR DE CONEXIÓN',
                OrmFactory::connectionError($e, $database)->getMessage(),
            );
        }
    }
}
