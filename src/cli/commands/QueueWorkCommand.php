<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use Override;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\Queue;

/**
 * Procesa la cola de trabajos.
 *
 *   php versaCLI app:queue:work                     sin fin (con supervisor o systemd)
 *   php versaCLI app:queue:work --stop-when-empty   hasta vaciarla (desde un cron, cada minuto)
 *   php versaCLI app:queue:work correos             otra cola (Queue::push(..., queue: 'correos'))
 */
class QueueWorkCommand extends BaseCommand
{
    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        $flags = array_filter($params, static fn($p): bool => is_string($p) && str_starts_with($p, '--'));
        $names = array_values(array_diff($params, $flags));
        $queue = (string) ($names[0] ?? 'default');
        $untilEmpty = in_array('--stop-when-empty', $flags, true);

        $this->info("Procesando la cola «{$queue}»" . ($untilEmpty ? ' hasta vaciarla' : '') . '…');
        $done = 0;
        while (true) {
            if (Queue::work($queue)) {
                $done++;

                continue;
            }

            if ($untilEmpty) {
                break;
            }

            sleep(3);
        }

        $this->success("Trabajos procesados: {$done}");

        return 0;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Procesa la cola de trabajos (--stop-when-empty para usarlo desde un cron)';
    }
}
