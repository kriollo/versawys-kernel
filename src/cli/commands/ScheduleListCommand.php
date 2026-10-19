<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use DateTimeImmutable;
use Override;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\Schedule;

/**
 * Lista las tareas de app/schedule.php: frecuencia, próxima ejecución y resultado de la última.
 */
class ScheduleListCommand extends BaseCommand
{
    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        $report = Schedule::load()->report(new DateTimeImmutable());
        if ($report['tasks'] === []) {
            $this->info(
                'No hay tareas programadas. Se definen en app/schedule.php (ver documentacion/Tareas_Programadas.md).',
            );
        }

        $line = "%-14s %-44s %-16s %-17s %s\n";
        if ($report['tasks'] !== []) {
            printf($line, 'Id', 'Tarea', 'Frecuencia', 'Próxima', 'Última');
        }

        foreach ($report['tasks'] as $task) {
            $last = $task['last_run'];
            $lastText = $last === null
                ? '—'
                : sprintf(
                    '%s %s (%d ms)',
                    $last['started_at'],
                    $last['exit'] === 0 ? 'ok' : 'ERROR ' . $last['exit'],
                    $last['ms'],
                );
            printf(
                $line,
                $task['id'],
                mb_strimwidth($task['task'] . ($task['overlaps'] ? '' : ' [sin solapar]'), 0, 44, '…'),
                $task['expression'],
                $task['next_run'] ?? '—',
                $lastText,
            );
        }

        // Aunque no haya tareas: así se comprueba el crontab apenas se configura
        if ($report['cron_running']) {
            $this->success('El crontab está llamando al programador (última vez: ' . $report['last_tick'] . ').');
        } else {
            $this->warning(
                'El crontab no está llamando a app:schedule:run'
                . ($report['last_tick'] === null ? '' : ' (última vez: ' . $report['last_tick'] . ')')
                . '. Agrega esta línea:',
            );
            echo '  ' . $report['cron_line'] . "\n";
        }

        return 0;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Lista las tareas programadas con su próxima ejecución y el resultado de la última';
    }
}
