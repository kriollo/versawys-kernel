<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\RouteManager;

final class RouteParamOrderTest extends TestCase
{
    public function testUnParametroDeLaUrlEnOtraPosicionDelMetodoEsUnError(): void
    {
        $routes = RouteManager::parseRouteSource(<<<'PHP'
            <?php
            use versaWYS\kernel\Router;
            use versaWYS\kernel\Tests\Fixtures\ControladorParametros;
            // description: Busca
            // path-param name:kbId type:int description:Base
            // path-param name:tokenEmpresa type:string description:Empresa
            Router::post('/search/{kbId}/{tokenEmpresa}', [ControladorParametros::class, 'cruzados']);
            // description: Ordenada
            // path-param name:idCosa type:int description:Cosa
            // path-param name:slug type:string description:Slug
            Router::get('/cosa/{idCosa}/{slug}', [ControladorParametros::class, 'ordenados']);
            PHP, 'admin');

        self::assertSame('error', $routes[0]['issues'][0]['level']);
        self::assertStringContainsString(
            '`{kbId}` llega a $idEmpresa y no a $kbId',
            $routes[0]['issues'][0]['message'],
        );
        self::assertSame([], $routes[1]['issues']);
    }
}
