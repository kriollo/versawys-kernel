<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use Override;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\Maintenance;

/**
 * Pone el sitio en mantenimiento: php versaCLI app:down [--message="..."] [--retry=60] [--secret=clave].
 */
class DownCommand extends BaseCommand
{
    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        $options = [];
        foreach ($params as $param) {
            if (!is_string($param) || preg_match('/^--(message|retry|secret)=(.*)$/s', $param, $m) !== 1) {
                continue;
            }

            $options[$m[1]] = $m[2];
        }

        $state = Maintenance::down(
            $options['message'] ?? '',
            (int) ($options['retry'] ?? 60),
            $options['secret'] ?? '',
        );

        $this->success('Sitio en mantenimiento: todo responde 503 hasta php versaCLI app:up');
        $this->info("Para entrar igual, abre una vez: /{$state['secret']}");

        return 0;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Pone el sitio en mantenimiento (503) con una clave para que el equipo siga entrando';
    }
}
