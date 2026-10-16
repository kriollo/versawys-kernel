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
        $tasks = Schedule::load()->tasks();
        if ($tasks === []) {
            $this->info(
                'No hay tareas programadas. Se definen en app/schedule.php (ver documentacion/Tareas_Programadas.md).',
            );

            return 0;
        }

        $now = new DateTimeImmutable();
        $line = "%-14s %-44s %-16s %-17s %s\n";
        printf($line, 'Id', 'Tarea', 'Frecuencia', 'Próxima', 'Última');
        foreach ($tasks as $task) {
            $last = $task->lastRun();
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
                $task->id(),
                mb_strimwidth($task->description() . ($task->overlaps() ? '' : ' [sin solapar]'), 0, 44, '…'),
                $task->expression(),
                $task->nextRun($now)?->format('Y-m-d H:i') ?? '—',
                $lastText,
            );
        }

        return 0;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Lista las tareas programadas con su próxima ejecución y el resultado de la última';
    }
}
