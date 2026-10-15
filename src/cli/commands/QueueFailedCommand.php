<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use Override;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\Queue;

/**
 * Lista los trabajos que agotaron sus intentos, con su último error. Se reprograman con queue:retry.
 */
class QueueFailedCommand extends BaseCommand
{
    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        $failed = Queue::failed();
        if ($failed === []) {
            $this->success('No hay trabajos fallidos.');

            return 0;
        }

        foreach ($failed as $job) {
            echo sprintf(
                "#%d  %s  %s  (%d intentos)\n    %s\n",
                (int) $job['id'],
                date('Y-m-d H:i:s', (int) $job['failed_at']),
                (string) $job['job'],
                (int) $job['attempts'],
                (string) $job['error'],
            );
        }

        $this->info('Reprogramar: php versaCLI app:queue:retry <id> | all');

        return 0;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Lista los trabajos de la cola que agotaron sus intentos';
    }
}
