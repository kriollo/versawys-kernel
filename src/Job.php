<?php

declare(strict_types=1);

namespace versaWYS\kernel;

/**
 * Un trabajo para la cola. Se crea con el contenedor (constructor tipado) y recibe los datos con los que se
 * encoló. Si lanza una excepción, la cola lo reintenta; agotados los intentos queda en `app:queue:failed`.
 *
 *     final class EnviarBienvenida implements Job
 *     {
 *         public function __construct(private readonly MailService $mail) {}
 *
 *         public function handle(array $data): void
 *         {
 *             $this->mail->enviarTemplate(['email' => $data['email']], 'Bienvenido', 'mail/bienvenida', $data);
 *         }
 *     }
 *
 *     Queue::push(EnviarBienvenida::class, ['email' => $email]);
 */
interface Job
{
    /** @param array<array-key, mixed> $data los datos de Queue::push, tal como se guardaron (JSON) */
    public function handle(array $data): void;
}
