<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Mime\Email;
use versaWYS\kernel\MailSender;

/** Una imagen embebida se ve en el cuerpo: el `cid:logo` del HTML apunta al Content-ID de su parte inline. */
final class MailEmbedTest extends TestCase
{
    public function testElCidDelHtmlApuntaALaImagenEmbebida(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($png, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            true,
        ));

        try {
            $email = (new Email())
                ->from('a@example.com')
                ->to('b@example.com')
                ->subject('Prueba')
                ->html('<img src="cid:logo"><img src="cid:viejo">');
            $sender = (new ReflectionClass(MailSender::class))->newInstanceWithoutConstructor();
            $metodo = new \ReflectionMethod(MailSender::class, 'embedImage');
            $metodo->invoke($sender, $email, [
                ['path' => $png, 'id' => 'logo', 'mime' => 'image/png'],
                // Un id con dominio, como lo enviaban las versiones anteriores
                ['path' => $png, 'id' => 'viejo@symfony', 'mime' => 'image/png'],
            ]);

            // El HTML viene en quoted-printable (cortes de línea suaves): se decodifica para leer los cid
            $mime = quoted_printable_decode($email->toString());
            preg_match_all('/src=3D"cid:([^"]+)"|src="cid:([^"]+)"/', $mime, $refs);
            preg_match_all('/Content-ID: <([^>]+)>/', $mime, $ids);
            $referencias = array_values(array_filter([...$refs[1], ...$refs[2]]));

            self::assertCount(2, $referencias);
            foreach ($referencias as $cid) {
                self::assertContains($cid, $ids[1], "cid:{$cid} no corresponde a ninguna imagen embebida");
            }
            self::assertStringContainsString('Content-Disposition: inline', $mime);
        } finally {
            unlink($png);
        }
    }
}
