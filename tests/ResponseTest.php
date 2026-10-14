<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\Response;

final class ResponseTest extends TestCase
{
    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $config = ['build' => ['debug' => false], 'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/']];
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
    }

    protected function tearDown(): void
    {
        global $config;

        $config = $this->originalConfig;
    }

    /** @return array<mixed> */
    private static function send(array $data): array
    {
        ob_start();
        Response::json($data, 500);

        return json_decode((string) ob_get_clean(), true);
    }

    public function testInProductionTheSqlOfAnExceptionNeverReachesTheClient(): void
    {
        // Lo que devuelve un controlador con 'message' => $e->getMessage() ante un fallo de VersaORM
        $body = self::send([
            'success' => 0,
            'message' => 'PDO engine execution error: SQL failed (get): SELECT password FROM versa_users WHERE id = 1',
            'errors' => 'SQLSTATE[42P01]: Undefined table: 7 ERROR: no existe la relación «versa_userz»',
            'trace' => '#0 /var/www/app/models/Users.php(40)',
        ]);

        self::assertStringNotContainsString('SELECT', (string) json_encode($body));
        self::assertStringNotContainsString('SQLSTATE', (string) json_encode($body));
        self::assertArrayNotHasKey('trace', $body);
        self::assertMatchesRegularExpression('/^Error interno del servidor \(ref\. [A-Za-z0-9._-]+\)\.$/', $body['message']);
    }

    public function testValidationErrorsAreKept(): void
    {
        $body = self::send([
            'success' => 0,
            'message' => 'Los datos enviados no son correctos',
            'errors' => ['email' => 'El campo email no es un email valido', 'ruta' => 'Error en /home/app/x.php:12'],
        ]);

        self::assertSame('Los datos enviados no son correctos', $body['message']);
        self::assertSame('El campo email no es un email valido', $body['errors']['email']);
        // Solo el texto con detalle interno se reemplaza
        self::assertStringStartsWith('Error interno del servidor', $body['errors']['ruta']);
    }

    public function testInDebugTheDetailIsShown(): void
    {
        global $config;
        $config['build']['debug'] = true;

        $body = self::send(['success' => 0, 'message' => 'SQL failed (get): SELECT 1']);

        self::assertSame('SQL failed (get): SELECT 1', $body['message']);
    }

    public function testSuccessfulResponsesAreUntouched(): void
    {
        $body = self::send(['success' => 1, 'message' => 'Exportado a /tmp/reporte.php.csv']);

        self::assertSame('Exportado a /tmp/reporte.php.csv', $body['message']);
    }
}
