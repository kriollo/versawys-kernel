<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use Override;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\Schedule;
use versaWYS\kernel\ScheduledTask;

/**
 * Ejecuta una tarea programada por su id. Lo usa app:schedule:run para correr cada tarea en su
 * propio proceso; a mano sirve para probar una tarea: php versaCLI app:schedule:exec <id>.
 */
class ScheduleExecCommand extends BaseCommand
{
    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        $id = $this->getParam($params, 0);
        $task = Schedule::load()->find($id);
        if (!$task instanceof ScheduledTask) {
            $this->error("No hay una tarea con id «{$id}» (ver app:schedule:list)");

            return 1;
        }

        $code = $task->execute();
        if ($code === null) {
            $this->warning('Omitida: la ejecución anterior sigue corriendo (' . $task->description() . ')');

            return 0;
        }

        return $code;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Ejecuta una tarea programada por su id (lo usa app:schedule:run)';
    }
}
