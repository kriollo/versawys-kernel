<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

use Exception;
use VersaORM\VersaModel;
use VersaORM\VersaORMException;
use versaWYS\kernel\model\OrmFactory;
use versaWYS\kernel\model\VersaORMModel;

// TODO: validar si el modo debug está activo y preguntar si está seguro

class MigrationManager extends VersaORMModel
{
    protected string $table = 'versa_migrations';

    private static string $path = 'app/migrations/';

    private static string $pathClass = '\\app\\migrations\\';

    public function __construct()
    {
        $config = ConfigManager::getConfig();
        $debug = $config['build']['debug'] ?? false;

        $versaOrm = OrmFactory::make(
            $config
            + [
                'versaorm' => [
                    'debug' => $debug,
                ],
            ],
        );
        VersaModel::setORM($versaOrm);

        // Initialize VersaORMModel static instance
        if (!VersaORMModel::isBooted()) {
            VersaORMModel::setOrmInstance($versaOrm);
        }
    }

    public function runUP($close = true): void
    {
        $className = '';

        try {
            $migrations = glob(self::$path . '*.php');
            $c = 0;
            foreach ($migrations as $migration) {
                $className = basename($migration, '.php');
                $fullClassName = self::$pathClass . $className;
                $fullPathClassName = self::$path . $className . '.php';

                if ($this->checkIfExecuted($className)) {
                    continue;
                }

                // check if file exists
                if (!file_exists($fullPathClassName)) {
                    CLIErrorHandler::warning(sprintf('No existe la migración %s.php', $className));
                    exit(1);
                }

                CLIErrorHandler::info(sprintf('Ejecutando... %s....:', $className));

                try {
                    // Una transacción por migración: en PostgreSQL, si falla a la mitad no queda nada
                    // aplicado ni registrado. En MySQL los cambios de esquema confirman solos.
                    self::orm()->beginTransaction();
                    // prettier-ignore
                    $result = (new $fullClassName())->up();
                    $c++;

                    if (!$result['success']) {
                        echo " ✗ FALLÓ\n";
                        self::orm()->rollBack();
                        CLIErrorHandler::handleMigrationResultError($result['message'], $className, 'up');
                    }

                    echo sprintf(' ✓ %s%s', $result['message'], PHP_EOL);

                    // Registrar migración ejecutada usando VersaORM
                    $migrationData = [
                        'name' => $className,
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                    $this->qb()->insert($migrationData);
                    self::orm()->commit();
                } catch (VersaORMException $e) {
                    self::orm()->rollBack();
                    echo " ✗ FALLÓ\n";
                    CLIErrorHandler::handleMigrationError($e, $className);
                }
            }

            if ($c === 0) {
                CLIErrorHandler::info('No hay migraciones pendientes.');
            } else {
                CLIErrorHandler::success($c . ' migración(es) ejecutada(s) con éxito.');
            }
        } catch (VersaORMException $versaormException) {
            CLIErrorHandler::handleMigrationError($versaormException, $className);
        }
    }

    public function runDown(string $fileDown): void
    {
        if (!file_exists(self::$path . ($fileDown . '.php'))) {
            CLIErrorHandler::warning("No existe la migración {$fileDown}.php");

            return;
        }

        $className = basename($fileDown, '.php');
        $fullClassName = self::$pathClass . $className;

        CLIErrorHandler::info(sprintf('Verificando migración %s...', $className));
        if (!$this->checkIfExecuted($className)) {
            CLIErrorHandler::info('No ejecutada.');

            return;
        }

        CLIErrorHandler::info('Ejecutando...');
        // prettier-ignore
        $result = (new $fullClassName())->down();

        if (!$result['success']) {
            CLIErrorHandler::handleMigrationResultError($result['message'], $className, 'down');
        }

        echo $result['message'] . PHP_EOL;

        try {
            $this->qb()->where('name', '=', $className)->delete();

            CLIErrorHandler::success('Migración ejecutada con éxito.');

            return;
        } catch (VersaORMException $versaormException) {
            CLIErrorHandler::handleMigrationError($versaormException, $className);
        }
    }

    public static function createMigration($name): void
    {
        $date = date('YmdHis');
        $migrationName = sprintf('m%s_%s', $date, $name);
        $migrationFile = self::$path . ($migrationName . '.php');

        if (file_exists($migrationFile)) {
            if (!CLIHelper::confirm("La migración {$migrationFile} ya existe. Desea sobreescribirlo?")) {
                CLIErrorHandler::info('Operación cancelada.');
                exit(1);
            }

            unlink($migrationFile);
        }

        // Punto de partida: crea una tabla con el nombre de la migración. Para cambiar una tabla
        // existente se reemplaza create() por table() y dropIfExists() por lo inverso.
        $template = <<<'EOT'
            <?php

            declare(strict_types=1);

            namespace app\migrations;

            use VersaORM\Schema\Blueprint;
            use VersaORM\VersaORMException;
            use versaWYS\kernel\model\VersaORMModel;

            class $migrationName extends VersaORMModel
            {
                public function up(): array
                {
                    try {
                        $this->orm()->schemaBuilder()->create('$tableName', static function (Blueprint $table): void {
                            $table->id();
                            // $table->string('nombre', 120);
                            // $table->foreignId('id_empresa');
                            // $table->foreign(['id_empresa'])->references('id')->on('empresas')->onDelete('CASCADE');
                            $table->timestamps();
                        });

                        return ['message' => 'Migración ejecutada con éxito.', 'success' => true];
                    } catch (VersaORMException $versaormException) {
                        return ['message' => $versaormException->getMessage(), 'success' => false];
                    }
                }

                public function down(): array
                {
                    try {
                        $this->orm()->schemaBuilder()->dropIfExists('$tableName');

                        return ['message' => 'Migración ejecutada con éxito.', 'success' => true];
                    } catch (VersaORMException $versaormException) {
                        return ['message' => $versaormException->getMessage(), 'success' => false];
                    }
                }
            }

            EOT;

        $template = str_replace(
            ['$migrationName', '$tableName'],
            [$migrationName, CLIHelper::tableName((string) $name)],
            $template,
        );

        CLIHelper::ensureDirectory(self::$path);

        if (file_put_contents($migrationFile, $template)) {
            chmod($migrationFile, 0o775);
            CLIErrorHandler::success("Migración {$migrationFile} creada.");
        } else {
            CLIErrorHandler::warning("No se pudo crear el archivo: {$migrationFile}");
        }
    }

    public function checkIfExecuted($migration): bool
    {
        try {
            // Verificar si la tabla existe
            $tablas = $this->orm()->schema('tables');
            if (in_array('versa_migrations', $tablas, strict: true)) {
                // Buscar si la migración ya fue ejecutada
                $result = $this->orm()->exec('SELECT name FROM versa_migrations WHERE name = ?', [$migration]);

                return count($result) > 0;
            }
        } catch (Exception $exception) {
            CLIErrorHandler::info('Debug: Error en checkIfExecuted: ' . $exception->getMessage());

            // Si hay error, asumir que la tabla no existe
            return false;
        }

        return false;
    }

    public function rollback(): void
    {
        // Los nombres llevan timestamp (mYYYYMMDDHHMMSS_), así que el mayor es la última migración
        $migrations = $this->orm()->exec('SELECT * FROM versa_migrations ORDER BY name DESC');
        if (isset($migrations[0])) {
            $this->runDown($migrations[0]['name']);
        } else {
            CLIErrorHandler::info('No hay migraciones para revertir.');
        }
    }

    public function refresh($runSeeders = false, $verbose = false): void
    {
        CLIErrorHandler::info('🔄 Reiniciando base de datos...');
        $tablas = $this->orm()->schema('tables');
        if (in_array('versa_migrations', $tablas, strict: true)) {
            $this->rollbackAllQuiet($verbose);
        }

        CLIErrorHandler::info('⬆️  Ejecutando migraciones...');
        $this->runUPQuiet($verbose);

        if ($runSeeders) {
            CLIErrorHandler::info('🌱 Ejecutando seeders...');
            (new SeederManager())->runAllSeedersQuiet($verbose);
        }

        CLIErrorHandler::success('✅ Reinicio completado exitosamente.');
    }

    /**
     * Ejecuta rollback de todas las migraciones de forma silenciosa.
     */
    public function rollbackAllQuiet($verbose = false): void
    {
        $migrations = $this->orm()->exec('SELECT * FROM versa_migrations ORDER BY name DESC');
        $count = 0;

        foreach ($migrations as $m) {
            if ($verbose) {
                CLIErrorHandler::info(sprintf('Ejecutando rollback... %s... :', $m['name']));
            }

            if (!file_exists(self::$path . $m['name'] . '.php')) {
                if ($verbose) {
                    CLIErrorHandler::warning('No existe la migración ' . $m['name'] . '.php');
                }

                $this->qb()->where('name', '=', $m['name'])->delete();

                continue;
            }

            $className = basename($m['name'], '.php');
            $fullClassName = self::$pathClass . $className;

            // prettier-ignore
            $result = (new $fullClassName())->down();

            if (!$result['success']) {
                CLIErrorHandler::handleMigrationResultError($result['message'], $m['name'], 'down');
            }

            if ($verbose) {
                echo $result['message'] . PHP_EOL;
            }

            // Eliminar el registro de la migración después del rollback exitoso
            $tablas = $this->orm()->schema('tables');
            if (in_array('versa_migrations', $tablas, strict: true)) {
                $this->qb()->where('name', '=', $m['name'])->delete();
            }

            $count++;
        }

        if (!$verbose && $count > 0) {
            echo "   ↩️  {$count} migración(es) revertida(s)\n";
        }
    }

    /**
     * Ejecuta migraciones UP de forma silenciosa.
     */
    public function runUPQuiet($verbose = false): void
    {
        $className = '';

        try {
            $migrations = glob(self::$path . '*.php');
            $c = 0;
            foreach ($migrations as $migration) {
                $className = basename($migration, '.php');
                $fullClassName = self::$pathClass . $className;
                $fullPathClassName = self::$path . $className . '.php';

                if ($this->checkIfExecuted($className)) {
                    continue;
                }

                if (!file_exists($fullPathClassName)) {
                    CLIErrorHandler::warning(sprintf('No existe la migración %s.php', $className));
                    exit(1);
                }

                if ($verbose) {
                    CLIErrorHandler::info(sprintf('Ejecutando... %s....:', $className));
                }

                try {
                    // Una transacción por migración: en PostgreSQL, si falla a la mitad no queda nada
                    // aplicado ni registrado. En MySQL los cambios de esquema confirman solos.
                    self::orm()->beginTransaction();
                    // prettier-ignore
                    $result = (new $fullClassName())->up();
                    $c++;

                    if (!$result['success']) {
                        if ($verbose) {
                            echo " ✗ FALLÓ\n";
                        }

                        self::orm()->rollBack();

                        CLIErrorHandler::handleMigrationResultError($result['message'], $className, 'up');
                    }

                    if ($verbose) {
                        echo sprintf(' ✓ %s%s', $result['message'], PHP_EOL);
                    }

                    // Registrar migración ejecutada usando VersaORM
                    $migrationData = [
                        'name' => $className,
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                    $this->orm()->table('versa_migrations')->insert($migrationData);
                    self::orm()->commit();
                } catch (VersaORMException $e) {
                    self::orm()->rollBack();
                    if ($verbose) {
                        echo " ✗ FALLÓ\n";
                    }

                    CLIErrorHandler::handleMigrationError($e, $className);
                }
            }

            if (!$verbose && $c > 0) {
                echo "   ⬆️  {$c} migración(es) ejecutada(s)\n";
            } elseif (!$verbose) {
                echo "   ℹ️  No hay migraciones pendientes\n";
            } else {
                CLIErrorHandler::success($c . ' migración(es) ejecutada(s) con éxito.');
            }
        } catch (VersaORMException $versaormException) {
            CLIErrorHandler::handleMigrationError($versaormException, $className);
        }
    }

    /**
     * Lista las migraciones del proyecto indicando cuáles ya se ejecutaron.
     */
    public function status(): void
    {
        $executed = in_array('versa_migrations', self::orm()->schema('tables'), strict: true)
            ? array_column(self::orm()->exec('SELECT name FROM versa_migrations'), 'name')
            : [];

        $pending = 0;
        $files = glob(self::$path . '*.php');

        foreach ($files === false ? [] : $files as $file) {
            $name = basename($file, '.php');
            $done = in_array($name, $executed, strict: true);
            $pending += $done ? 0 : 1;
            echo
                (
                    $done
                        ? CLIErrorHandler::colorize('  ✓ ejecutada ', 'green')
                        : CLIErrorHandler::colorize('  • pendiente ', 'yellow')
                )
                    . $name
                    . PHP_EOL;
        }

        CLIErrorHandler::info(
            $pending === 0 ? 'No hay migraciones pendientes.' : $pending . ' migración(es) pendiente(s).',
        );
    }

    public function dropAll(): void
    {
        $isMysql = str_contains(strtolower((string) (ConfigManager::getConfig()['database']['driver'] ?? '')), 'mysql');
        $tablas = $this->orm()->schema('tables');

        // MySQL no elimina dependencias con CASCADE: se desactivan las FK durante el borrado
        if ($isMysql) {
            $this->orm()->exec('SET FOREIGN_KEY_CHECKS = 0');
        }

        foreach ($tablas as $tabla) {
            if (preg_match('/^\w+$/', (string) $tabla) !== 1) {
                CLIErrorHandler::warning("Nombre de tabla no soportado, se omite: {$tabla}");

                continue;
            }

            try {
                $this->orm()->exec($isMysql ? "DROP TABLE `{$tabla}`" : "DROP TABLE \"{$tabla}\" CASCADE");
                CLIErrorHandler::info("Tabla {$tabla} eliminada.");
            } catch (Exception $e) {
                CLIErrorHandler::warning("No se pudo eliminar la tabla {$tabla}: " . $e->getMessage());
            }
        }

        if ($isMysql) {
            $this->orm()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
