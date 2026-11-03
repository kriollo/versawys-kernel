<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Exception\ExceptionInterface;
use versaWYS\kernel\ContentType;
use versaWYS\kernel\MailSender;

/**
 * CVE-2026-48019 (CRLF en correos de Laravel): un salto de línea en el nombre, el asunto o el correo
 * del destinatario no debe crear encabezados nuevos (Bcc, Cc) en el mensaje que sale por SMTP.
 */
final class MailHeaderTest extends TestCase
{
    private mixed $originalConfig;

    /** @var list<string> mensajes tal como saldrían por SMTP */
    private array $sent = [];

    /** @var list<string> destinatarios del sobre SMTP (RCPT TO) */
    private array $recipients = [];

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $config = ['mail' => [
            'username' => 'panel@ejemplo.cl',
            'name_from' => 'Panel',
            'host' => 'null',
            'port' => 25,
            'transport' => 'null',
        ]];
    }

    protected function tearDown(): void
    {
        global $config;

        $config = $this->originalConfig;
    }

    public function testASubjectOrNameWithLineBreaksDoesNotAddHeaders(): void
    {
        $inyeccion = "Hola\r\nBcc: espia@atacante.cl\r\nCc: otro@atacante.cl";

        self::assertTrue($this->sender()->send(
            ['email' => 'ana@ejemplo.cl', 'name' => $inyeccion],
            $inyeccion,
            'cuerpo',
            ContentType::TEXT,
        ));

        [$headers] = explode("\r\n\r\n", $this->sent[0], 2);
        self::assertDoesNotMatchRegularExpression('/^(Bcc|Cc):/mi', $headers);
        self::assertSame(['ana@ejemplo.cl'], $this->recipients);
    }

    public function testAnAddressWithLineBreaksIsRejected(): void
    {
        $this->expectException(ExceptionInterface::class);

        $this->sender()->send(
            ['email' => "ana@ejemplo.cl\r\nBcc: espia@atacante.cl", 'name' => 'Ana'],
            'Asunto',
            'cuerpo',
            ContentType::TEXT,
        );
    }

    private function sender(): MailSender
    {
        $sender = new MailSender();
        $transport = new class($this->sent, $this->recipients) extends AbstractTransport {
            public function __construct(
                private array &$sent,
                private array &$recipients,
            ) {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                $this->sent[] = $message->toString();
                foreach ($message->getEnvelope()->getRecipients() as $address) {
                    $this->recipients[] = $address->getAddress();
                }
            }

            public function __toString(): string
            {
                return 'captura://';
            }
        };
        (new ReflectionProperty(MailSender::class, 'mailer'))->setValue($sender, new Mailer($transport));

        return $sender;
    }
}
