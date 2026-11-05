<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

/** Middleware de las rutas que registra RouterTest */
final class RouterTestMiddleware
{
    /** @return array<string, mixed> */
    public function deny(): array
    {
        return ['success' => 0, 'message' => 'Sin permiso', 'code' => 403];
    }
}
