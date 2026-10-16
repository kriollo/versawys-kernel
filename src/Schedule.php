<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use DateTimeInterface;
use RuntimeException;

/**
 * Programador de tareas. Las tareas se definen en `app/schedule.php` y el servidor tiene una sola
 * línea en el crontab: `* * * * * php /ruta/del/proyecto/versaCLI app:schedule:run`.
 *
 *     return static function (Schedule $schedule): void {
 *         $schedule->cron(CompressVideos::class)->everyMinutes(5)->withoutOverlapping();
 *         $schedule->cron(AgendaRecordatorios::class)->dailyAt('08:00');
 *         $schedule->command('app:queue:work --stop-when-empty')->everyMinute()->withoutOverlapping();
 *     };
 */
final class Schedule
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    /** Lee `app/schedule.php` del proyecto. Sin ese archivo, no hay tareas. */
    public static function load(): self
    {
        $schedule = new self();
        $file = getcwd() . '/app/schedule.php';
        if (!is_file($file)) {
            return $schedule;
        }

        $define = require $file;
        if (!is_callable($define)) {
            throw new RuntimeException('app/schedule.php debe devolver una función que reciba Schedule');
        }

        $define($schedule);

        return $schedule;
    }

    /** Una clase de app/cron (con su método run()). */
    public function cron(string $class): ScheduledTask
    {
        return $this->tasks[] = new ScheduledTask('cron', $class);
    }

    /** Un comando de versaCLI, con sus opciones: `app:queue:work --stop-when-empty`. */
    public function command(string $command): ScheduledTask
    {
        return $this->tasks[] = new ScheduledTask('command', $command);
    }

    /** @return list<ScheduledTask> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /** @return list<ScheduledTask> las que corresponde ejecutar en ese minuto */
    public function due(DateTimeInterface $at): array
    {
        return array_values(array_filter($this->tasks, static fn(ScheduledTask $t): bool => $t->isDue($at)));
    }

    public function find(string $id): ?ScheduledTask
    {
        foreach ($this->tasks as $task) {
            if ($task->id() === $id) {
                return $task;
            }
        }

        return null;
    }

    /** @internal Carpeta de candados y estado de las tareas. */
    public static function dir(): string
    {
        global $config;

        return rtrim((string) ($config['schedule']['dir'] ?? 'versaWYS/cache/schedule'), '/') . '/';
    }
}
