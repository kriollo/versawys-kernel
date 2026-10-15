<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\CLIHelper;

final class CliTest extends TestCase
{
    protected function tearDown(): void
    {
        CLIHelper::$interactive = true;
    }

    public function testWithoutInteractionQuestionsTakeTheirDefaultWithoutReadingInput(): void
    {
        CLIHelper::$interactive = false;

        ob_start();
        $answer = CLIHelper::ask('Nombre', 'admin');
        $overwrite = CLIHelper::confirm('¿Sobreescribir?');
        ob_end_clean();

        self::assertSame('admin', $answer);
        // Una confirmación sin interacción es "no": nada destructivo pasa sin --force
        self::assertFalse($overwrite);
    }

    public function testForceAnswersYesToConfirmations(): void
    {
        $argv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['versaCLI', 'route:make:x', '--force'];
        CLIHelper::$interactive = false;

        try {
            self::assertTrue(CLIHelper::confirm('¿Sobreescribir?'));
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }

    public function testModelAndMigrationShareTheTableName(): void
    {
        self::assertSame('tableros', CLIHelper::tableName('tableros'));
        self::assertSame('notas_rapidas', CLIHelper::tableName('NotasRapidas'));
        self::assertSame('notas_rapidas', CLIHelper::tableName('notasRapidas'));
    }
}
