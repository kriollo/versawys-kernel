<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use DateTimeImmutable;
use Override;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\cli\CommandExecutor;
use versaWYS\kernel\Loggin;
use versaWYS\kernel\Schedule;

/**
 * Lanza las tareas de app/schedule.php que corresponden a este minuto, cada una en su propio
 * proceso: una tarea lenta no atrasa a las demás. Va en el crontab, una sola línea por servidor:
 *
 *   * * * * * /usr/bin/php /ruta/del/proyecto/versaCLI app:schedule:run >> /ruta/del/proyecto/versaWYS/logs/cron.log 2>&1
 *
 * Con la ruta completa de PHP (cron no la encuentra en su PATH); app:schedule:list muestra la línea exacta.
 */
class ScheduleRunCommand extends BaseCommand
{
    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        Schedule::tick();
        $due = Schedule::load()->due(new DateTimeImmutable());
        foreach ($due as $task) {
            // La salida de cada tarea va al mismo log que el crontab: si falla, queda registrado
            CommandExecutor::runInBackground(
                'schedule:exec',
                [$task->id()],
                outputFile: Loggin::directory() . '/cron.log',
            );
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
