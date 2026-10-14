<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\CommandExecutor;

final class CommandExecutorTest extends TestCase
{
    public function testEveryParameterReachesTheCommandAsASingleLiteralArgument(): void
    {
        $build = new \ReflectionMethod(CommandExecutor::class, 'buildParamsString');
        $params = ['a; rm -rf /', '$(id)', "it's", ['nombre' => '`whoami`'], null, true];

        $line = (string) $build->invoke(null, $params);

        // El shell devuelve cada valor tal cual, sin ejecutar nada
        exec('printf "%s\n" ' . $line, $output);
        self::assertSame(['a; rm -rf /', '$(id)', "it's", '{"nombre":"`whoami`"}', '', '1'], $output);
    }
}
