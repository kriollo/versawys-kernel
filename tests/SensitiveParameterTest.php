<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * #[SensitiveParameter] oculta el valor en las trazas de error. Si una herramienta de formato deja el atributo sin
 * su `use`, PHP lo resuelve en el namespace del kernel, donde no existe, y lo ignora sin aviso.
 */
final class SensitiveParameterTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function sensitiveParameters(): iterable
    {
        yield 'hash' => [\versaWYS\kernel\helpers\Functions::class, 'hash', 'password'];
        yield 'verifyHash' => [\versaWYS\kernel\helpers\Functions::class, 'verifyHash', 'password'];
        yield 'CSRF de la sesión' => [\versaWYS\kernel\helpers\Functions::class, 'validateCSRFToken', 'tokenSession'];
        yield 'CSRF enviado' => [\versaWYS\kernel\helpers\Functions::class, 'validateCSRFToken', 'tokenFormUser'];
        yield 'revocar JWT' => [\versaWYS\kernel\Session::class, 'revokeToken', 'jwt'];
        yield 'política de contraseñas' => [
            \versaWYS\kernel\GlobalMiddleware::class,
            'validatePolicyPassword',
            'password',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveParameters')]
    public function testTheAttributeResolvesToPhpsSensitiveParameter(
        string $class,
        string $method,
        string $parameter,
    ): void {
        $param = array_values(array_filter(
            (new \ReflectionMethod($class, $method))->getParameters(),
            static fn(\ReflectionParameter $p): bool => $p->getName() === $parameter,
        ))[0];

        self::assertSame(
            [\SensitiveParameter::class],
            array_map(static fn(\ReflectionAttribute $a): string => $a->getName(), $param->getAttributes()),
        );
    }
}
