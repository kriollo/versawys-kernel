<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use versaWYS\kernel\File;

final class FileTest extends TestCase
{
    private string $dir;

    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        // Producción y sin cabeceras de API: los errores son excepciones, no respuestas con exit()
        $config = ['build' => ['debug' => false], 'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/']];
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $this->dir = sys_get_temp_dir() . '/versawys-file-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        global $config;

        $config = $this->originalConfig;
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    public function testAFailedUploadIsRejected(): void
    {
        $this->expectException(Exception::class);

        new File($this->upload('foto.png', 'image/png', '', UPLOAD_ERR_PARTIAL));
    }

    public function testTheTypeComesFromTheContentNotFromTheBrowser(): void
    {
        $file = new File($this->upload('foto.png', 'image/png', "<?php echo 'hola';"));

        self::assertNotSame('image/png', $file->getType());
        self::assertStringContainsString('php', $file->getType());
    }

    public function testTheOriginalNameIsSanitized(): void
    {
        $file = new File($this->upload('../../.htaccess', 'text/plain', 'x'));

        self::assertSame('htaccess', $file->getName());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unsafeDestinations(): iterable
    {
        yield 'php' => ['subidas/foto.php'];
        yield 'php en medio' => ['subidas/foto.php.png'];
        yield 'phar en mayúsculas' => ['subidas/foto.PHAR'];
        yield 'oculto' => ['subidas/.htaccess'];
        yield 'sube un nivel' => ['subidas/../config.png'];
        yield 'sube desde el inicio' => ['../foto.png'];
    }

    #[DataProvider('unsafeDestinations')]
    public function testUnsafeDestinationsAreRejectedBeforeMoving(string $destination): void
    {
        $file = new File($this->upload('foto.png', 'image/png', 'x'));

        $this->expectException(RuntimeException::class);
        $file->moveToNewName($this->dir . '/' . $destination);
    }

    private function upload(string $name, string $declaredType, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $tmp = $this->dir . '/tmp-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);

        return [
            'name' => $name,
            'type' => $declaredType,
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => strlen($content),
        ];
    }
}
