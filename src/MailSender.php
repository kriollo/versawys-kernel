<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Exception;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

class MailSender
{
    private Mailer $mailer;

    public function __construct()
    {
        global $config;
        $user = $config['mail']['username'] ?? '';
        $pass = $config['mail']['password'] ?? '';
        $host = $config['mail']['host'] ?? '';
        $port = $config['mail']['port'] ?? 0;
        $transportScheme = $config['mail']['transport'] ?? 'smtp';
        $secure = $config['mail']['secure'] ?? false; // false, tls, ssl

        if ($secure === 'ssl') {
            $transportScheme = 'smtps';
        }

        $auth = '';
        if ($user !== '') {
            $auth = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
        }

        $options = [];
        if (in_array($secure, [false, 'false', ''], true)) {
            $options['auto_tls'] = false;
        } elseif (($config['mail']['verify_peer'] ?? true) === false) {
            // Solo para servidores SMTP internos con certificado autofirmado (mail.verify_peer = false)
            $options['verify_peer'] = false;
            $options['verify_peer_name'] = false;
            $options['allow_self_signed'] = true;
        }

        $query = http_build_query($options, '', '&', PHP_QUERY_RFC3986);
        $dsn = sprintf('%s://%s%s:%s%s', $transportScheme, $auth, $host, $port, $query !== '' ? "?{$query}" : '');

        $transport = Transport::fromDsn($dsn);
        $this->mailer = new Mailer($transport);
    }

    /**
     * @throws Exception
     */
    public function send(
        array $to,
        string $subject,
        string $body,
        ContentType $type,
        array $context = [],
        array $imagesEmbed = [],
        array $attachments = [],
    ): bool {
        global $config, $twig;

        $email = (new Email())
            ->from(new Address($config['mail']['username'], $config['mail']['name_from']))
            ->to(new Address($to['email'], $to['name']))
            ->subject($subject);

        match ($type) {
            ContentType::HTML => $email->html($body),
            ContentType::TEXT => $email->text($body),
            ContentType::TWIG => $email->html($twig->render($body, $context)),
        };

        $email = $this->attachFile($email, $attachments);
        $email = $this->embedImage($email, $imagesEmbed);

        try {
            $this->mailer->send($email);

            return true;
        } catch (Exception|TransportExceptionInterface $e) {
            $error_message = 'Error al enviar email: ' . $e->getMessage();
            Loggin::error($error_message, 'MailSender');

            // Nunca romper el flujo principal por falla de SMTP.
            return false;
        }
    }

    private function attachFile(Email $email, array $attachments): Email
    {
        foreach ($attachments as $attachment) {
            $email->addPart(new DataPart(new File($attachment)));
        }

        return $email;
    }

    /**
     * Las imágenes embebidas, referenciadas en el HTML como `cid:<id>` (por ejemplo `<img src="cid:logo">`). Se
     * embeben por nombre: Symfony les da su Content-ID y reemplaza `cid:<id>` en el HTML por ese Content-ID. Con un
     * Content-ID propio (`logo@symfony`) el `cid:logo` del HTML no coincidía y el cliente de correo mostraba la imagen
     * como adjunto en vez de en el cuerpo.
     */
    private function embedImage(Email $email, array $imagesEmbed): Email
    {
        foreach ($imagesEmbed as $image) {
            $nombre = trim((string) ($image['id'] ?? ''));
            // Un id con dominio (logo@symfony, de versiones anteriores) se referencia en el HTML sin él
            $nombre = $nombre === '' ? bin2hex(random_bytes(8)) : explode('@', $nombre)[0];
            $email->embedFromPath((string) $image['path'], $nombre, $image['mime'] ?? null);
        }

        return $email;
    }
}
