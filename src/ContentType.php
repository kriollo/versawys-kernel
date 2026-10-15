<?php

declare(strict_types=1);

namespace versaWYS\kernel;

/**
 * Tipo de contenido de un correo (MailSender / MailService). En su propio archivo para que Composer lo cargue
 * solo: dentro de MailSender.php solo existía después de cargar MailSender.
 */
enum ContentType: string
{
    case HTML = 'html';
    case TEXT = 'text';
    case TWIG = 'twig';
}
