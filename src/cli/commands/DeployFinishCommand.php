<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\cli\CLIErrorHandler;

/**
 * Todo lo que va después de copiar el código al servidor, en un solo hook de despliegue. Cuando el
 * kernel agrega un paso, ningún versa_deploy.yml de los proyectos tiene que cambiar.
 */
class DeployFinishCommand extends BaseCommand
{
    /** Ajustes sin secretos versionados en el proyecto (ver config:merge) */
    private const string SHARED_CONFIG = 'versaWYS/config.compartida.json';

    /** @var array<string, string> comando de versaCLI => qué hace */
    private const array STEPS = [
        'config:merge ' . self::SHARED_CONFIG => 'Ajustes versionados → config.json',
        'migrate:up' => 'Migraciones pendientes',
        'cache:twig' => 'Caché de Twig (es compartida entre releases)',
        'config:templateCache:true' => 'Caché de plantillas activa',
        'command:refresh' => 'Registro de comandos',
        'app:check:deploy --production' => 'Revisión final del servidor',
    ];

    /**
     * @param array<mixed> $params
     */
    #[\Override]
    public function execute(array $params = []): int
    {
        $number = 0;
        foreach (self::STEPS as $command => $title) {
            echo
                CLIErrorHandler::colorize(sprintf("\n▶ %d/%d %s", ++$number, count(self::STEPS), $title), 'blue')
                    . "\n";

            if (str_starts_with($command, 'config:merge') && !is_file(self::SHARED_CONFIG)) {
                CLIErrorHandler::info('No hay ' . self::SHARED_CONFIG . ': se omite.');

                continue;
            }

            // Mismo PHP que este proceso; sin terminal: las confirmaciones toman su valor por defecto
            passthru(
                escapeshellarg(PHP_BINARY)
                . ' versaCLI '
                . implode(' ', array_map(escapeshellarg(...), explode(' ', $command)))
                . ' < /dev/null',
                $exitCode,
            );
            if ($exitCode !== 0) {
                CLIErrorHandler::error("Falló «php versaCLI {$command}»: el despliegue no está completo.");

                return 1;
            }
        }

        CLIErrorHandler::success('Despliegue terminado.');

        return 0;
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Pasos posteriores al despliegue: config:merge, migraciones, cachés y app:check:deploy --production';
    }

    #[\Override]
    public function getHelp(): string
    {
        $steps = '';
        foreach (self::STEPS as $command => $title) {
            $steps .= sprintf("  %-36s %s\n", "php versaCLI {$command}", $title);
        }

        return (
            "Uso: php versaCLI app:deploy:finish\n\n"
            . "Pensado como único hook post_deploy de versaDeploy. Ejecuta, en orden y deteniéndose en el\n"
            . "primero que falle:\n\n{$steps}"
        );
    }
}
