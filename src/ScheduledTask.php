<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Una tarea del programador (ver Schedule): qué se ejecuta, cuándo y si puede solaparse.
 */
final class ScheduledTask
{
    private string $expression = '* * * * *';

    private bool $withoutOverlapping = false;

    /**
     * @param 'command'|'cron' $type
     * @param string $target clase de app/cron (con run()) o comando de versaCLI con sus opciones
     */
    public function __construct(
        public readonly string $type,
        public readonly string $target,
    ) {}

    /** Identifica la tarea (su candado y su estado) aunque cambie la frecuencia. */
    public function id(): string
    {
        return substr(sha1($this->type . '|' . $this->target), 0, 12);
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function overlaps(): bool
    {
        return !$this->withoutOverlapping;
    }

    /** Una expresión cron de 5 campos, para lo que no cubren los atajos. */
    public function cron(string $expression): self
    {
        new CronExpression($expression); // valida al definir, no al ejecutar
        $this->expression = $expression;

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    /** Cada N minutos (N debe dividir a 60: 2, 5, 10, 15, 20, 30). */
    public function everyMinutes(int $minutes): self
    {
        if ($minutes < 1 || (60 % $minutes) !== 0) {
            throw new InvalidArgumentException("everyMinutes({$minutes}): debe dividir a 60");
        }

        return $this->cron("*/{$minutes} * * * *");
    }

    public function hourly(): self
    {
        return $this->hourlyAt(0);
    }

    public function hourlyAt(int $minute): self
    {
        return $this->cron("{$minute} * * * *");
    }

    public function daily(): self
    {
        return $this->dailyAt('00:00');
    }

    /** @param string $time HH:MM */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron("{$minute} {$hour} * * *");
    }

    /** @param int $weekday 0 (domingo) a 6 (sábado) */
    public function weeklyOn(int $weekday, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron("{$minute} {$hour} * * {$weekday}");
    }

    public function monthlyOn(int $day, string $time = '00:00'): self
    {
        [$hour, $minute] = self::time($time);

        return $this->cron("{$minute} {$hour} {$day} * *");
    }

    /** Si la ejecución anterior sigue corriendo, esta vuelta se omite. */
    public function withoutOverlapping(): self
    {
        $this->withoutOverlapping = true;

        return $this;
    }

    public function isDue(DateTimeInterface $at): bool
    {
        return (new CronExpression($this->expression))->isDue($at);
    }

    public function nextRun(DateTimeInterface $from): ?DateTimeImmutable
    {
        return (new CronExpression($this->expression))->nextRun($from);
    }

    public function description(): string
    {
        return $this->type === 'cron' ? $this->target : 'versaCLI ' . $this->target;
    }

    /**
     * Ejecuta la tarea y guarda su estado (`versaWYS/cache/schedule/<id>.json`).
     *
     * @return int|null código de salida; null si se omitió porque la anterior sigue corriendo
     */
    public function execute(): ?int
    {
        $dir = Schedule::dir();
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException("No se pudo crear {$dir}");
        }

        $lock = null;
        if ($this->withoutOverlapping) {
            $lock = fopen($dir . $this->id() . '.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                Loggin::info('Tarea omitida: la anterior sigue corriendo', 'schedule', [
                    'tarea' => $this->description(),
                ]);

                return null;
            }
        }

        $started = microtime(true);
        $error = null;

        try {
            $code = $this->type === 'cron' ? $this->runCron() : $this->runCommand();
        } catch (Throwable $throwable) {
            $code = 1;
            $error = $throwable::class . ': ' . $throwable->getMessage();
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $status = [
            'started_at' => date('Y-m-d H:i:s', (int) $started),
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'exit' => $code,
            'error' => $error,
        ];
        file_put_contents($dir . $this->id() . '.json', json_encode($status, JSON_UNESCAPED_UNICODE));

        $code === 0 ? Loggin::info('Tarea terminada', 'schedule', [
                'tarea' => $this->description(),
                'ms' => $status['ms'],
            ]) : Loggin::error('Tarea con error', 'schedule', ['tarea' => $this->description()] + $status);

        return $code;
    }

    /** @return array{started_at: string, ms: int, exit: int, error: ?string}|null la última ejecución */
    public function lastRun(): ?array
    {
        $file = Schedule::dir() . $this->id() . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($data)) {
            return null;
        }

        return [
            'started_at' => (string) ($data['started_at'] ?? ''),
            'ms' => (int) ($data['ms'] ?? 0),
            'exit' => (int) ($data['exit'] ?? 1),
            'error' => isset($data['error']) ? (string) $data['error'] : null,
        ];
    }

    private function runCron(): int
    {
        $task = Container::getInstance()->get($this->target);
        if (!method_exists($task, 'run')) {
            throw new InvalidArgumentException("{$this->target} no tiene el método run()");
        }

        $task->run();

        return 0;
    }

    private function runCommand(): int
    {
        // Mismo PHP que este proceso; los argumentos se escapan uno por uno
        $args = preg_split('/\s+/', trim($this->target));
        $args = $args === false ? [] : $args;
        passthru(
            escapeshellarg(PHP_BINARY)
            . ' versaCLI '
            . implode(' ', array_map(escapeshellarg(...), $args))
            . ' < /dev/null',
            $code,
        );

        return $code;
    }

    /** @return array{int, int} hora y minuto */
    private static function time(string $time): array
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            throw new InvalidArgumentException("Hora inválida (HH:MM): {$time}");
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
