<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;
use versaWYS\kernel\GlobalMiddleware;
use versaWYS\kernel\helpers\Functions;
use versaWYS\kernel\Session;

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
        yield 'hash' => [Functions::class, 'hash', 'password'];
        yield 'verifyHash' => [Functions::class, 'verifyHash', 'password'];
        yield 'CSRF de la sesión' => [Functions::class, 'validateCSRFToken', 'tokenSession'];
        yield 'CSRF enviado' => [Functions::class, 'validateCSRFToken', 'tokenFormUser'];
        yield 'revocar JWT' => [Session::class, 'revokeToken', 'jwt'];
        yield 'política de contraseñas' => [
            GlobalMiddleware::class,
            'validatePolicyPassword',
            'password',
        ];
    }

    #[DataProvider('sensitiveParameters')]
    public function testTheAttributeResolvesToPhpsSensitiveParameter(
        string $class,
        string $method,
        string $parameter,
    ): void {
        $param = array_values(array_filter(
            (new ReflectionMethod($class, $method))->getParameters(),
            static fn(ReflectionParameter $p): bool => $p->getName() === $parameter,
        ))[0];

        self::assertSame(
            [SensitiveParameter::class],
            array_map(static fn(ReflectionAttribute $a): string => $a->getName(), $param->getAttributes()),
        );
    }
}
