<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use DateTimeInterface;
use RuntimeException;
use versaWYS\kernel\cli\CommandExecutor;

/**
 * Programador de tareas. Las tareas se definen en `app/schedule.php` y el servidor tiene una sola
 * línea en el crontab: `* * * * * /ruta/de/php /ruta/del/proyecto/versaCLI app:schedule:run` (ruta completa de PHP: ver report()).
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

    /**
     * Lo que muestran `app:schedule:list` y el panel: cada tarea con su próxima y su última ejecución,
     * y si el crontab está llamando a `app:schedule:run`.
     *
     * @return array{
     *     tasks: list<array{id: string, task: string, type: string, expression: string, frequency: string, overlaps: bool, next_run: ?string, last_run: ?array{started_at: string, ms: int, exit: int, error: ?string}}>,
     *     last_tick: ?string,
     *     cron_running: bool,
     *     cron_line: string
     * }
     */
    public function report(DateTimeInterface $now): array
    {
        $tasks = [];
        foreach ($this->tasks as $task) {
            $tasks[] = [
                'id' => $task->id(),
                'task' => $task->description(),
                'type' => $task->type,
                'expression' => $task->expression(),
                'frequency' => (new CronExpression($task->expression()))->describe(),
                'overlaps' => $task->overlaps(),
                'next_run' => $task->nextRun($now)?->format('Y-m-d H:i'),
                'last_run' => $task->lastRun(),
            ];
        }

        $tick = self::lastTick();

        return [
            'tasks' => $tasks,
            'last_tick' => $tick === null ? null : date('Y-m-d H:i:s', $tick),
            // app:schedule:run corre cada minuto: más de 2 minutos sin pasar es que el crontab no lo llama
            'cron_running' => $tick !== null && ($now->getTimestamp() - $tick) <= 120,
            // Ruta completa de PHP: cron corre con un PATH mínimo y no encuentra un php instalado
            // fuera de /usr/bin (mise, cPanel). La salida va a un log: un error no se pierde en /dev/null
            'cron_line' =>
                '* * * * * '
                    . CommandExecutor::phpBinary()
                    . ' '
                    . getcwd()
                    . '/versaCLI app:schedule:run >> '
                    . getcwd()
                    . '/versaWYS/logs/cron.log 2>&1',
        ];
    }

    /** Lo llama app:schedule:run en cada pasada. */
    public static function tick(): void
    {
        $dir = self::dir();
        if (is_dir($dir) || mkdir($dir, 0o775, true) || is_dir($dir)) {
            file_put_contents($dir . 'last-tick', (string) time());
        }
    }

    /** @return int|null la última vez que corrió app:schedule:run (timestamp) */
    public static function lastTick(): ?int
    {
        $file = self::dir() . 'last-tick';

        return is_file($file) ? (int) file_get_contents($file) : null;
    }

    /** @internal Carpeta de candados y estado de las tareas. */
    public static function dir(): string
    {
        global $config;

        return rtrim((string) ($config['schedule']['dir'] ?? 'versaWYS/cache/schedule'), '/') . '/';
    }
}
