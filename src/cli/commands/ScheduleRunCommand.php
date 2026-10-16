<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use DateTimeImmutable;
use Override;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\cli\CommandExecutor;
use versaWYS\kernel\Schedule;

/**
 * Lanza las tareas de app/schedule.php que corresponden a este minuto, cada una en su propio
 * proceso: una tarea lenta no atrasa a las demás. Va en el crontab, una sola línea por servidor:
 *
 *   * * * * * php /ruta/del/proyecto/versaCLI app:schedule:run >> /dev/null 2>&1
 */
class ScheduleRunCommand extends BaseCommand
{
    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        $due = Schedule::load()->due(new DateTimeImmutable());
        foreach ($due as $task) {
            CommandExecutor::runInBackground('schedule:exec', [$task->id()]);
            $this->info('Lanzada: ' . $task->description());
        }

        if ($due === []) {
            $this->info('Ninguna tarea corresponde a este minuto.');
        }

        return 0;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Lanza las tareas programadas de este minuto (va en el crontab, cada minuto)';
    }
}
