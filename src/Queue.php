<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use VersaORM\Schema\Blueprint;
use VersaORM\VersaORM;

/**
 * Cola de trabajos en la base de datos (tabla `versa_jobs`), procesada por `php versaCLI app:queue:work`.
 *
 *     Queue::push(EnviarBienvenida::class, ['email' => $email]);              // lo antes posible
 *     Queue::push(RecordarPago::class, ['id' => $id], delay: 3600);           // en una hora
 *
 * Con `queue.driver: "sync"` el trabajo se ejecuta en el acto (pruebas, o un servidor sin worker).
 * Un trabajo que falla se reintenta (`tries`, 1 min × intento de espera); agotados los intentos queda en
 * `app:queue:failed` y se reprograma con `app:queue:retry`.
 */
final class Queue
{
    public const string TABLE = 'versa_jobs';

    /** Un trabajo tomado hace más de esto se da por abandonado (el worker murió) y se vuelve a tomar */
    private const int RETRY_AFTER = 600;

    /** La migración del proyecto la llama en up() (ver documentacion/Colas.md del esqueleto). */
    public static function createTable(VersaORM $orm): void
    {
        $orm->schemaBuilder()->create(
            self::TABLE,
            static function (Blueprint $table): void {
                $table->id();
                $table->string('queue', 60)->default('default');
                $table->string('job', 255);
                $table->text('payload');
                $table->integer('attempts')->default(0);
                $table->integer('max_attempts')->default(3);
                $table->bigInteger('available_at');
                $table->bigInteger('reserved_at')->nullable();
                $table->bigInteger('failed_at')->nullable();
                $table->text('error')->nullable();
                $table->bigInteger('created_at');
                $table->index(['queue', 'available_at']);
            },
            true,
        );
    }

    /**
     * @param class-string<Job> $job
     * @param array<array-key, mixed> $data se guarda como JSON
     *
     * @return int|null el id del trabajo; null con el driver sync
     */
    public static function push(
        string $job,
        array $data = [],
        int $delay = 0,
        string $queue = 'default',
        int $tries = 3,
    ): ?int {
        if (!is_subclass_of($job, Job::class)) {
            throw new InvalidArgumentException("{$job} no implementa " . Job::class);
        }

        global $config;
        if (($config['queue']['driver'] ?? 'database') === 'sync') {
            self::run($job, $data);

            return null;
        }

        $now = time();

        return self::orm()
            ->table(self::TABLE)
            ->insertGetId([
                'queue' => $queue,
                'job' => $job,
                'payload' => json_encode($data, JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'max_attempts' => max(1, $tries),
                'available_at' => $now + max(0, $delay),
                'created_at' => $now,
            ]);
    }

    /** Toma y ejecuta el siguiente trabajo de la cola. false si no había ninguno listo. */
    public static function work(string $queue = 'default'): bool
    {
        $orm = self::orm();
        $now = time();
        $rows = $orm
            ->table(self::TABLE)
            ->where('queue', '=', $queue)
            ->whereNull('failed_at')
            ->where('available_at', '<=', $now)
            ->whereRaw('(reserved_at IS NULL OR reserved_at < ?)', [$now - self::RETRY_AFTER])
            ->orderBy('id')
            ->limit(1)
            ->getAll();
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return false;
        }

        $id = (int) $row['id'];
        $attempts = (int) $row['attempts'];
        // Tomarlo solo si nadie lo tomó entre la consulta y aquí: otro worker ya habría subido los intentos
        $claimed = $orm
            ->table(self::TABLE)
            ->where('id', '=', $id)
            ->where('attempts', '=', $attempts)
            ->update(['reserved_at' => $now, 'attempts' => $attempts + 1]);
        if ($claimed !== 1) {
            return true;
        }

        try {
            $data = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
            self::run((string) $row['job'], is_array($data) ? $data : []);
            $orm->table(self::TABLE)->where('id', '=', $id)->delete();
        } catch (Throwable $throwable) {
            $failed = ($attempts + 1) >= (int) $row['max_attempts'];
            $error = $throwable::class . ': ' . $throwable->getMessage();
            $orm
                ->table(self::TABLE)
                ->where('id', '=', $id)
                ->update([
                    'reserved_at' => null,
                    'error' => mb_substr($error, 0, 2000),
                    'failed_at' => $failed ? $now : null,
                    'available_at' => $now + (60 * ($attempts + 1)),
                ]);
            Loggin::error("Trabajo {$row['job']} #{$id} falló (intento " . ($attempts + 1) . ')', 'queue', [
                'error' => $error,
                'definitivo' => $failed,
            ]);
        }

        return true;
    }

    /** @return list<array<string, mixed>> los trabajos que agotaron sus intentos */
    public static function failed(): array
    {
        return array_values(self::orm()->table(self::TABLE)->whereNotNull('failed_at')->orderBy('id')->getAll());
    }

    /** Reprograma un trabajo fallido (o todos, sin id). Devuelve cuántos. */
    public static function retry(?int $id = null): int
    {
        $query = self::orm()->table(self::TABLE)->whereNotNull('failed_at');
        if ($id !== null) {
            $query->where('id', '=', $id);
        }

        return $query->update(['failed_at' => null, 'attempts' => 0, 'available_at' => time()]);
    }

    /** @param array<array-key, mixed> $data */
    private static function run(string $job, array $data): void
    {
        $instance = Container::getInstance()->get($job);
        if (!$instance instanceof Job) {
            throw new RuntimeException("{$job} no implementa " . Job::class);
        }

        $instance->handle($data);
    }

    private static function orm(): VersaORM
    {
        global $versaOrm;

        if (!$versaOrm instanceof VersaORM) {
            throw new RuntimeException('La cola necesita la base de datos y VersaORM no está inicializado.');
        }

        return $versaOrm;
    }
}
