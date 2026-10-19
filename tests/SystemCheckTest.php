<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\SystemCheck;

final class SystemCheckTest extends TestCase
{
    public function testElDriverDeLaBaseDeDatosSaleDeLaConfiguracion(): void
    {
        self::assertArrayHasKey('pdo_pgsql', SystemCheck::extensions(['database' => [
            'driver' => 'postgresql',
            'database' => 'app',
        ]]));
        self::assertArrayHasKey('pdo_mysql', SystemCheck::extensions(['database' => [
            'driver' => 'mysql',
            'database' => 'app',
        ]]));
        // Recién creada, la configuración trae un motor por defecto que nadie eligió todavía
        self::assertArrayNotHasKey('pdo_mysql', SystemCheck::extensions(['database' => [
            'driver' => 'mysql',
            'database' => '',
        ]]));
        self::assertArrayHasKey('redis', SystemCheck::extensions(['redis' => ['enabled' => true]]));

        // Antes de instalar no hay motor elegido: basta uno de los dos
        $labels = array_column(SystemCheck::run(), 'label');
        self::assertContains('pdo_mysql o pdo_pgsql (driver de la base de datos)', $labels);
    }

    public function testLasHerramientasDeDesarrolloNoSonRequisito(): void
    {
        // phpunit pide ext-tokenizer, pero no se instala en producción (--no-dev)
        self::assertArrayNotHasKey('tokenizer', SystemCheck::extensions());
    }

    public function testPasaSoloSiTodoEstaBien(): void
    {
        self::assertTrue(SystemCheck::passes([['ok' => true], ['ok' => true]]));
        self::assertFalse(SystemCheck::passes([['ok' => true], ['ok' => false]]));
    }
}
