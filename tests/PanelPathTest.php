<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use versaWYS\kernel\GlobalMiddleware;

/** onlyUserWithAccess compara la petición con las URL del menú, que se guardan relativas al panel. */
final class PanelPathTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function casos(): iterable
    {
        yield 'panel por defecto' => ['/admin/tableros', 'admin', '/tableros'];
        yield 'con query string' => ['/admin/tableros?page=2', 'admin', '/tableros'];
        yield 'barra final' => ['/admin/orders/products/', 'admin', '/orders/products'];
        yield 'URL propia del panel' => ['/gestion/tableros', 'gestion', '/tableros'];
        yield '/admin con URL propia' => ['/admin/tableros', 'gestion', '/tableros'];
        yield 'raíz del panel' => ['/admin/', 'admin', '/'];
        yield 'fuera del panel' => ['/tienda/carro', 'admin', '/tienda/carro'];
        yield 'prefijo parecido' => ['/administracion/x', 'admin', '/administracion/x'];
    }

    #[DataProvider('casos')]
    public function testRutaRelativaAlPanel(string $uri, string $customUrl, string $esperada): void
    {
        self::assertSame($esperada, GlobalMiddleware::panelPath($uri, $customUrl));
    }
}
