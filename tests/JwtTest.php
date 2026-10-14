<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use Firebase\JWT\JWT;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use versaWYS\kernel\Session;

final class JwtTest extends TestCase
{
    private const string KEY = 'clave-de-prueba-de-64-caracteres-0123456789abcdef0123456789abcd';

    private string $cwd;

    private string $dir;

    private mixed $originalConfig;

    private Session $session;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $config = [
            'session' => ['key' => self::KEY, 'user_cookie' => ['lifetime' => 3600, 'lifetime_remember' => 86_400]],
            'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/'],
        ];

        // La revocación usa versaWYS/cache/jwt_revoked/ relativo a la raíz del proyecto
        $this->cwd = (string) getcwd();
        $this->dir = sys_get_temp_dir() . '/versawys-jwt-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        chdir($this->dir);

        // Sin el constructor: no hace falta una sesión PHP para firmar y validar tokens
        $this->session = (new ReflectionClass(Session::class))->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void
    {
        global $config;

        chdir($this->cwd);
        exec('rm -rf ' . escapeshellarg($this->dir));
        $config = $this->originalConfig;
    }

    public function testATokenCarriesTheUserCompanyAndARevocableId(): void
    {
        $claims = $this->session->decodeJWT($this->session->generateJWT(['id' => 7, 'id_empresa' => '3'], 'off'));

        self::assertSame(7, $claims['id_user']);
        self::assertSame(3, $claims['empresa_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $claims['jti']);
        self::assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    public function testRememberMeUsesTheLongLifetime(): void
    {
        $claims = $this->session->decodeJWT($this->session->generateJWT(['id' => 7, 'id_empresa' => 3], 'on'));

        self::assertSame(86_400, $claims['exp'] - $claims['iat']);
    }

    public function testATokenWithoutUserOrCompanyIsNeverIssued(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->session->generateJWT(['email' => 'ana@ejemplo.cl', 'id_empresa' => 3], 'off');
    }

    /**
     * @return iterable<string, array{0: callable(string): string}>
     */
    public static function forgedTokens(): iterable
    {
        // Cambiar el usuario sin volver a firmar
        yield 'payload cambiado' => [static function (string $jwt): string {
            [$header, $payload, $signature] = explode('.', $jwt);
            $claims = json_decode(base64_decode(strtr($payload, '-_', '+/'), strict: true), true);
            $claims['id_user'] = 1;

            return (
                $header
                . '.'
                . rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=')
                . '.'
                . $signature
            );
        }];
        yield 'firmado con otra clave' => [
            static fn(string $jwt): string => JWT::encode(
                ['id_user' => 1, 'empresa_id' => 1, 'exp' => time() + 60],
                'otra-clave-de-al-menos-treinta-y-dos-bytes!!',
                'HS256',
            ),
        ];
        yield 'sin firma (alg none)' => [
            static fn(string $jwt): string => (
                rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=')
                . '.'
                . explode('.', $jwt)[1]
                . '.'
            ),
        ];
        yield 'vencido' => [
            static fn(string $jwt): string => JWT::encode(
                ['id_user' => 7, 'empresa_id' => 3, 'exp' => time() - 10],
                self::KEY,
                'HS256',
            ),
        ];
        yield 'texto cualquiera' => [static fn(string $jwt): string => 'no-es-un-token'];
    }

    #[DataProvider('forgedTokens')]
    public function testForgedOrExpiredTokensAreRejected(callable $forge): void
    {
        $jwt = $forge($this->session->generateJWT(['id' => 7, 'id_empresa' => 3], 'off'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $this->session->decodeJWT($jwt);
    }

    public function testRevokingWritesTheTokenIdUntilItExpires(): void
    {
        $jwt = $this->session->generateJWT(['id' => 7, 'id_empresa' => 3], 'off');
        $claims = $this->session->decodeJWT($jwt);

        $this->session->revokeToken($jwt);

        $file = 'versaWYS/cache/jwt_revoked/' . $claims['jti'];
        self::assertFileExists($file);
        self::assertSame((string) $claims['exp'], file_get_contents($file));
    }

    public function testATokenIdCannotWriteOutsideTheRevocationFolder(): void
    {
        // Firmado con la clave correcta pero con un jti armado: solo se aceptan 32 hex
        $jwt = JWT::encode(
            ['id_user' => 7, 'empresa_id' => 3, 'jti' => '../../../escapado', 'exp' => time() + 60],
            self::KEY,
            'HS256',
        );

        $this->session->revokeToken($jwt);

        self::assertFileDoesNotExist($this->dir . '/escapado');
        self::assertSame([], glob($this->dir . '/versaWYS/cache/jwt_revoked/*') ?: []);
    }

    public function testAnInvalidTokenIsNotRevokedNorFails(): void
    {
        $this->session->revokeToken('no-es-un-token');
        $this->session->revokeToken(null);

        self::assertDirectoryDoesNotExist($this->dir . '/versaWYS/cache/jwt_revoked');
    }
}
