<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;
use versaWYS\kernel\helpers\Functions;

final class CsrfTest extends TestCase
{
    public function testOnlyTheSameNonEmptyTokenIsValid(): void
    {
        $token = Functions::generateCSRFToken();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertTrue(Functions::validateCSRFToken($token, $token));
        self::assertFalse(Functions::validateCSRFToken($token, Functions::generateCSRFToken()));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: mixed}>
     */
    public static function invalidPairs(): iterable
    {
        yield 'los dos vacíos' => ['', ''];
        yield 'sesión sin token' => [null, 'abc'];
        yield 'token como arreglo' => ['abc', ['abc']];
        yield 'token numérico' => ['123', 123];
        yield 'prefijo del token' => ['abcdef', 'abc'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPairs')]
    public function testMalformedTokensAreRejectedWithoutErrors(mixed $session, mixed $sent): void
    {
        self::assertFalse(Functions::validateCSRFToken($session, $sent));
    }

    public function testEveryTokenIsDifferent(): void
    {
        $tokens = array_map(static fn(): string => Functions::generateCSRFToken(), range(1, 200));

        self::assertCount(200, array_unique($tokens));
    }
}
