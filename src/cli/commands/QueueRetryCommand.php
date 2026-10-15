<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use Override;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\Queue;

/**
 * Vuelve a encolar un trabajo fallido (php versaCLI app:queue:retry 12) o todos (queue:retry all).
 */
class QueueRetryCommand extends BaseCommand
{
    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        $target = $this->getParam($params, 0);
        if ($target !== 'all' && preg_match('/^\d+$/', $target) !== 1) {
            $this->error('Uso: php versaCLI app:queue:retry <id> | all');

            return 1;
        }

        $count = Queue::retry($target === 'all' ? null : (int) $target);
        $this->success("Trabajos reprogramados: {$count}");

        return $count > 0 || $target === 'all' ? 0 : 1;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Vuelve a encolar un trabajo fallido (<id>) o todos (all)';
    }
}
