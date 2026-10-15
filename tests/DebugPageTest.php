<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use versaWYS\kernel\ErrorHandler;

final class DebugPageTest extends TestCase
{
    public function testMuestraElMensajeYElCodigoDeLaLineaQueFallo(): void
    {
        $line = __LINE__ + 1;
        $e = new RuntimeException('El <b>tablero</b> no existe');

        $html = ErrorHandler::debugPage($e);

        // El mensaje va escapado: un dato del usuario en la excepción no se ejecuta
        self::assertStringContainsString('El &lt;b&gt;tablero&lt;/b&gt; no existe', $html);
        self::assertStringContainsString('tests/DebugPageTest.php:' . $line, $html);
        self::assertMatchesRegularExpression(
            '#<span class="hit"><b>' . $line . '</b>\s+\$e = new RuntimeException\(#',
            $html,
        );
        self::assertStringContainsString('<style nonce="', $html);
    }
}
