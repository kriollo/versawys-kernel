<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use versaWYS\kernel\CronExpression;
use versaWYS\kernel\Schedule;
use versaWYS\kernel\Tests\Fixtures\TareaDePrueba;

final class ScheduleTest extends TestCase
{
    private string $dir;

    private string $cwd;

    private mixed $originalConfig = null;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $this->cwd = (string) getcwd();
        $this->dir = sys_get_temp_dir() . '/versawys_schedule_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/app', 0o775, true);
        $config = [
            'schedule' => ['dir' => $this->dir . '/estado'],
            'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/'],
        ];
        TareaDePrueba::$corridas = 0;
        TareaDePrueba::$fallar = false;
    }

    protected function tearDown(): void
    {
        global $config;

        chdir($this->cwd);
        $config = $this->originalConfig;
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testExpresionesCron(): void
    {
        $at = static fn(string $when): DateTimeImmutable => new DateTimeImmutable($when);

        self::assertTrue((new CronExpression('*/5 * * * *'))->isDue($at('2026-10-15 10:05')));
        self::assertFalse((new CronExpression('*/5 * * * *'))->isDue($at('2026-10-15 10:07')));

        // Lunes a viernes a las 8: el jueves 15 sí, el domingo 18 no
        self::assertTrue((new CronExpression('0 8 * * 1-5'))->isDue($at('2026-10-15 08:00')));
        self::assertFalse((new CronExpression('0 8 * * 1-5'))->isDue($at('2026-10-18 08:00')));

        // Domingo es 0 o 7
        self::assertTrue((new CronExpression('0 8 * * 7'))->isDue($at('2026-10-18 08:00')));

        // Día del mes y día de la semana restringidos: basta uno (el 1 del mes o cualquier domingo)
        self::assertTrue((new CronExpression('0 0 1 * 0'))->isDue($at('2026-10-18 00:00')));
        self::assertTrue((new CronExpression('0 0 1 * 0'))->isDue($at('2026-10-01 00:00')));
        self::assertFalse((new CronExpression('0 0 1 * 0'))->isDue($at('2026-10-15 00:00')));

        // `5/15`: desde el 5, cada 15
        self::assertTrue((new CronExpression('5/15 * * * *'))->isDue($at('2026-10-15 10:50')));
        self::assertFalse((new CronExpression('5/15 * * * *'))->isDue($at('2026-10-15 10:45')));

        self::assertSame(
            '2026-10-16 08:30',
            (new CronExpression('30 8 * * *'))
                ->nextRun($at('2026-10-15 09:00'))
                ?->format('Y-m-d H:i'),
        );
    }

    public function testExpresionesInvalidasSeRechazanAlDefinir(): void
    {
        foreach (['* * * *', '60 * * * *', '* 24 * * *', '5-1 * * * *', 'a * * * *'] as $bad) {
            try {
                new CronExpression($bad);
                self::fail("Debía rechazar «{$bad}»");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        (new Schedule())
            ->cron(TareaDePrueba::class)
            ->everyMinutes(7);
    }

    public function testAtajosDeFrecuencia(): void
    {
        $s = new Schedule();

        self::assertSame('30 8 * * *', $s->cron('X')->dailyAt('08:30')->expression());
        self::assertSame('*/10 * * * *', $s->cron('X')->everyMinutes(10)->expression());
        self::assertSame('15 * * * *', $s->cron('X')->hourlyAt(15)->expression());
        self::assertSame('0 9 * * 1', $s->cron('X')->weeklyOn(1, '09:00')->expression());
        self::assertSame('0 3 1 * *', $s->cron('X')->monthlyOn(1, '03:00')->expression());
    }

    public function testLeeAppScheduleYSabeQueCorresponde(): void
    {
        file_put_contents($this->dir . '/app/schedule.php', <<<'PHP'
            <?php
            return static function (versaWYS\kernel\Schedule $s): void {
                $s->cron(versaWYS\kernel\Tests\Fixtures\TareaDePrueba::class)->everyMinutes(5);
                $s->command('app:queue:work --stop-when-empty')->dailyAt('03:00');
            };
            PHP);
        chdir($this->dir);

        $schedule = Schedule::load();

        self::assertCount(2, $schedule->tasks());
        self::assertCount(1, $schedule->due(new DateTimeImmutable('2026-10-15 10:05')));
        self::assertCount(2, $schedule->due(new DateTimeImmutable('2026-10-15 03:00')));
        self::assertSame($schedule->tasks()[1], $schedule->find($schedule->tasks()[1]->id()));
    }

    public function testEjecutaLaTareaYGuardaSuResultado(): void
    {
        $task = (new Schedule())->cron(TareaDePrueba::class);

        self::assertSame(0, $task->execute());
        self::assertSame(1, TareaDePrueba::$corridas);
        self::assertSame(0, $task->lastRun()['exit'] ?? null);

        TareaDePrueba::$fallar = true;
        self::assertSame(1, $task->execute());
        self::assertStringContainsString('la tarea falló', $task->lastRun()['error'] ?? '');
    }

    public function testSinSolaparSeOmiteSiLaAnteriorSigueCorriendo(): void
    {
        $task = (new Schedule())
            ->cron(TareaDePrueba::class)
            ->withoutOverlapping();
        mkdir($this->dir . '/estado', 0o775, true);

        // Otra ejecución tiene el candado
        $lock = fopen($this->dir . '/estado/' . $task->id() . '.lock', 'c');
        self::assertNotFalse($lock);
        flock($lock, LOCK_EX);

        try {
            self::assertNull($task->execute());
            self::assertSame(0, TareaDePrueba::$corridas);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame(0, $task->execute(), 'liberado el candado, corre');
        self::assertSame(1, TareaDePrueba::$corridas);
    }

    public function testElReporteDiceSiElCrontabEstaLlamandoAlProgramador(): void
    {
        $schedule = new Schedule();
        $schedule->cron(TareaDePrueba::class)->dailyAt('03:00');
        $now = new DateTimeImmutable();

        $report = $schedule->report($now);
        self::assertCount(1, $report['tasks']);
        self::assertSame('0 3 * * *', $report['tasks'][0]['expression']);
        self::assertFalse($report['cron_running'], 'nunca corrió app:schedule:run');
        self::assertStringContainsString('versaCLI app:schedule:run', $report['cron_line']);
        // Con la ruta completa de PHP (cron no la encuentra en su PATH) y la salida en un log
        self::assertStringStartsWith('* * * * * ' . PHP_BINARY . ' ', $report['cron_line']);
        self::assertStringEndsWith('/versaWYS/logs/cron.log 2>&1', $report['cron_line']);

        Schedule::tick();
        self::assertTrue($schedule->report($now)['cron_running']);
        self::assertFalse(
            $schedule->report($now->modify('+10 minutes'))['cron_running'],
            'hace más de 2 minutos que no pasa',
        );
    }
}
