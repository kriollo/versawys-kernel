<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use VersaORM\VersaORM;
use versaWYS\kernel\Queue;
use versaWYS\kernel\Tests\Fixtures\TrabajoDePrueba;

final class QueueTest extends TestCase
{
    private string $db;

    private mixed $originalConfig = null;

    private mixed $originalOrm = null;

    protected function setUp(): void
    {
        global $config, $versaOrm;

        $this->originalConfig = $config ?? null;
        $this->originalOrm = $versaOrm ?? null;
        $config = ['build' => ['debug' => false], 'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/']];
        $this->db = sys_get_temp_dir() . '/queue_' . bin2hex(random_bytes(6)) . '.sqlite';
        $versaOrm = new VersaORM(['engine' => 'pdo', 'driver' => 'sqlite', 'database' => $this->db]);
        Queue::createTable($versaOrm);
        TrabajoDePrueba::$hechos = [];
        TrabajoDePrueba::$fallarVeces = 0;
    }

    protected function tearDown(): void
    {
        global $config, $versaOrm;

        $config = $this->originalConfig;
        $versaOrm = $this->originalOrm;
        if (is_file($this->db)) {
            unlink($this->db);
        }
    }

    public function testUnTrabajoEncoladoSeEjecutaUnaVezYSeBorra(): void
    {
        Queue::push(TrabajoDePrueba::class, ['email' => 'a@b.cl']);

        self::assertSame([], TrabajoDePrueba::$hechos, 'push no lo ejecuta: lo hace el worker');
        self::assertTrue(Queue::work());
        self::assertSame([['email' => 'a@b.cl']], TrabajoDePrueba::$hechos);
        self::assertFalse(Queue::work(), 'la cola quedó vacía');
    }

    public function testConRetrasoNoSeEjecutaAntesDeTiempo(): void
    {
        Queue::push(TrabajoDePrueba::class, delay: 3600);

        self::assertFalse(Queue::work());
    }

    public function testUnFalloSeReintentaYAlAgotarLosIntentosQuedaFallido(): void
    {
        global $versaOrm;

        TrabajoDePrueba::$fallarVeces = 5;
        $id = Queue::push(TrabajoDePrueba::class, ['n' => 1], tries: 2);

        self::assertTrue(Queue::work());
        self::assertSame([], Queue::failed(), 'el primer fallo se reintenta');
        // El reintento espera un minuto: lo adelantamos
        $versaOrm->table(Queue::TABLE)->where('id', '=', $id)->update(['available_at' => time()]);
        self::assertTrue(Queue::work());

        $failed = Queue::failed();
        self::assertCount(1, $failed);
        self::assertStringContainsString('servicio caído', (string) $failed[0]['error']);
        self::assertFalse(Queue::work(), 'un fallido no se vuelve a tomar solo');

        TrabajoDePrueba::$fallarVeces = 0;
        self::assertSame(1, Queue::retry($id));
        self::assertTrue(Queue::work());
        self::assertSame([['n' => 1]], TrabajoDePrueba::$hechos);
    }

    public function testElDriverSyncEjecutaEnElActo(): void
    {
        global $config;

        $config['queue']['driver'] = 'sync';

        self::assertNull(Queue::push(TrabajoDePrueba::class, ['x' => 1]));
        self::assertSame([['x' => 1]], TrabajoDePrueba::$hechos);
    }
}
