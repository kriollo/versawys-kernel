<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\cli\RouteManager;

final class RouteDuplicatesTest extends TestCase
{
    public function testTheSameMethodAndUrlInTwoFilesIsAnError(): void
    {
        $route = static fn (string $method, string $url): array => ['method' => $method, 'url' => $url, 'issues' => []];

        $all = RouteManager::markDuplicates([
            'UsersRoutes.php' => [$route('GET', '/admin/usuarios'), $route('POST', '/admin/usuarios')],
            'OtrosRoutes.php' => [$route('get', '/admin/usuarios')],
        ]);

        self::assertStringContainsString('OtrosRoutes.php', $all['UsersRoutes.php'][0]['issues'][0]['message']);
        self::assertSame('error', $all['OtrosRoutes.php'][0]['issues'][0]['level']);
        // Otro método en la misma URL no es un duplicado
        self::assertSame([], $all['UsersRoutes.php'][1]['issues']);
    }
}
