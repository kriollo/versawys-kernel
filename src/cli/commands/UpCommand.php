<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli\commands;

use Override;
use versaWYS\kernel\cli\BaseCommand;
use versaWYS\kernel\Maintenance;

/**
 * Saca el sitio del modo mantenimiento: php versaCLI app:up.
 */
class UpCommand extends BaseCommand
{
    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function execute(array $params = []): int
    {
        if (!Maintenance::up()) {
            $this->info('El sitio no estaba en mantenimiento.');

            return 0;
        }

        $this->success('El sitio volvió a estar disponible.');

        return 0;
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Saca el sitio del modo mantenimiento';
    }
}
