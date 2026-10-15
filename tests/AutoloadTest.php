<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Cada archivo de src/ declara un solo tipo, con el nombre del archivo: es lo que necesita el autoload
 * PSR-4. Un enum dentro de otro archivo (ContentType en MailSender.php) solo existía si antes se había
 * cargado esa otra clase.
 */
final class AutoloadTest extends TestCase
{
    public function testCadaArchivoDeSrcDeclaraUnTipoConSuNombre(): void
    {
        $problemas = [];
        $base = __DIR__ . '/../src';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base)) as $archivo) {
            if ($archivo->getExtension() !== 'php') {
                continue;
            }

            $codigo = (string) file_get_contents($archivo->getPathname());
            preg_match_all('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) (\w+)/m', $codigo, $m);
            $relativo = substr($archivo->getPathname(), strlen($base) + 1);
            // Los scripts de arranque (config.php, init_*.php) no declaran tipos
            if ($m[1] === [] && !preg_match('/^[a-z]/', $archivo->getBasename())) {
                $problemas[] = "{$relativo}: no declara ningún tipo";
            } elseif ($m[1] !== [] && $m[1] !== [$archivo->getBasename('.php')]) {
                $problemas[] = "{$relativo}: declara " . implode(', ', $m[1]);
            }
        }

        self::assertSame([], $problemas);
    }
}
