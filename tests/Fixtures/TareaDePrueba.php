<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

use RuntimeException;

/** Una tarea de app/cron para ScheduleTest. */
final class TareaDePrueba
{
    public static int $corridas = 0;

    public static bool $fallar = false;

    public function run(): void
    {
        if (self::$fallar) {
            throw new RuntimeException('la tarea falló');
        }

        self::$corridas++;
    }
}
