<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\GlobalMiddleware;

/** Cuándo una sesión no puede seguir: cuenta desactivada, empresa suspendida o una empresa que no es la de la cuenta. */
final class GlobalMiddlewareBloqueoTest extends TestCase
{
    public function testSoloElSuperadminOperaEnUnaEmpresaQueNoEsLaDeSuCuenta(): void
    {
        $admin = ['status' => 1, 'role' => 'admin', 'id_empresa' => 7, 'empresa_estado' => true];

        self::assertNull(GlobalMiddleware::blockedReason($admin, 7));
        self::assertSame('empresa_ajena', GlobalMiddleware::blockedReason($admin, 8));
        self::assertSame('empresa_ajena', GlobalMiddleware::blockedReason(['role' => 'user'] + $admin, 8));
        self::assertNull(GlobalMiddleware::blockedReason(['role' => 'superadmin'] + $admin, 8));
        // Sin empresa de sesión (otros usos) no se compara
        self::assertNull(GlobalMiddleware::blockedReason($admin));
    }

    public function testCuentaDesactivadaYEmpresaSuspendida(): void
    {
        self::assertSame('cuenta_desactivada', GlobalMiddleware::blockedReason([
            'status' => 0,
            'role' => 'admin',
            'id_empresa' => 7,
        ], 7));
        self::assertSame('empresa_suspendida', GlobalMiddleware::blockedReason([
            'status' => 1,
            'role' => 'admin',
            'id_empresa' => 7,
            'empresa_estado' => false,
        ], 7));
        self::assertNull(GlobalMiddleware::blockedReason([
            'status' => 1,
            'role' => 'superadmin',
            'id_empresa' => 7,
            'empresa_estado' => false,
        ], 7));
    }
}
