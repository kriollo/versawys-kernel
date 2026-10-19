<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use versaWYS\kernel\helpers\Functions;

final class SvgSanitizerTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function ataques(): array
    {
        return [
            'onload en la raíz' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><path d="M0 0"/></svg>'],
            'script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><path d="M0 0"/></svg>'],
            'script con CDATA' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><script><![CDATA[alert(1)]]></script></svg>',
            ],
            'enlace javascript' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><path d="M0 0"/></a></svg>',
            ],
            'use con tabulación en javascript' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><use href="java&#x09;script:alert(1)"/></svg>',
            ],
            'xlink:href externo' => [
                '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://evil.test/x.svg#a"/></svg>',
            ],
            'foreignObject' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml" onload="alert(1)"/></foreignObject></svg>',
            ],
            'animate onbegin' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><animate onbegin="alert(1)" attributeName="x" dur="1s"/></svg>',
            ],
            'set a un evento' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><set attributeName="onmouseover" to="alert(1)"/></svg>',
            ],
            'style con import' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url(https://evil.test/x.css);</style></svg>',
            ],
            'fill con url javascript' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><path fill="url(javascript:alert(1))" d="M0 0"/></svg>',
            ],
            'iframe' => ['<svg xmlns="http://www.w3.org/2000/svg"><iframe src="https://evil.test"/></svg>'],
        ];
    }

    #[DataProvider('ataques')]
    public function testNoQuedaNadaQuePuedaEjecutarse(string $svg): void
    {
        $limpio = strtolower(Functions::sanitizeSvgContent($svg));

        foreach ([
            'script',
            'javascript',
            'onload',
            'onbegin',
            'onmouseover',
            'foreignobject',
            'iframe',
            'evil.test',
            '<a',
            '<style',
            '<set',
            '<animate',
            'alert',
        ] as $peligro) {
            self::assertStringNotContainsString($peligro, $limpio);
        }
    }

    public function testUnDoctypeConEntidadesNoSeProcesa(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>';

        self::assertSame('', Functions::sanitizeSvgContent($xxe));
    }

    public function testUnIconoNormalSeConserva(): void
    {
        $icono = '<svg class="w-6 h-6" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M13.5 8H4m0-2v13"/><use href="#a"/><rect fill="url(#grad)" width="2" height="2"/></svg>';
        $limpio = Functions::sanitizeSvgContent($icono);

        foreach ([
            'class="w-6 h-6"',
            'aria-hidden="true"',
            'viewBox="0 0 24 24"',
            'stroke-linecap="round"',
            'd="M13.5 8H4m0-2v13"',
            'href="#a"',
            'fill="url(#grad)"',
        ] as $esperado) {
            self::assertStringContainsString($esperado, $limpio);
        }

        // Guardado escapado (proyectos 2.x): se decodifica y se limpia igual
        self::assertStringContainsString(
            'd="M13.5 8H4m0-2v13"',
            Functions::sanitizeSvgContent(htmlspecialchars($icono)),
        );
    }

    public function testLoQueNoEsUnSvgValidoQuedaVacio(): void
    {
        foreach (['', 'hola', '<div>x</div>', '<svg><path></svg>', '<SVG onload="alert(1)"></SVG>'] as $entrada) {
            self::assertSame('', Functions::sanitizeSvgContent($entrada), $entrada);
        }
    }
}
