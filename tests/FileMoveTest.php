<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use versaWYS\kernel\File;

/** Guardar un archivo subido: rutas, permisos y errores, sin perder el archivo ni romper la petición. */
final class FileMoveTest extends TestCase
{
    private string $dir;

    private mixed $originalConfig;

    protected function setUp(): void
    {
        global $config;

        $this->originalConfig = $config ?? null;
        $config = ['build' => ['debug' => false], 'logging' => ['dir' => sys_get_temp_dir() . '/versawys-test-logs/']];
        $_SERVER = ['REQUEST_METHOD' => 'POST'];
        $this->dir = sys_get_temp_dir() . '/versawys-move-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        global $config;

        $config = $this->originalConfig;
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' && rm -rf ' . escapeshellarg($this->dir));
    }

    public function testGuardaConElNombreOriginalSaneadoYCreaLaCarpeta(): void
    {
        $file = $this->file('Informe Final (v2).txt', 'contenido');

        self::assertTrue($file->moveToOriginalName($this->dir . '/nueva/sub'));

        $guardados = glob($this->dir . '/nueva/sub/*') ?: [];
        self::assertCount(1, $guardados);
        self::assertSame('contenido', file_get_contents($guardados[0]));
        self::assertDoesNotMatchRegularExpression('/[\s()]/', basename($guardados[0]));
    }

    public function testGuardaConUnNombreNuevoYCreaLaCarpeta(): void
    {
        $file = $this->file('a.txt', 'hola');

        self::assertTrue($file->moveToNewName($this->dir . '/x/y/final.txt'));
        self::assertSame('hola', file_get_contents($this->dir . '/x/y/final.txt'));
    }

    public function testUnaCarpetaSinPermisoDeEscrituraEsUnErrorControlado(): void
    {
        mkdir($this->dir . '/solo-lectura', 0o555);
        $file = $this->file('a.txt', 'hola');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Error en operación de archivo');

        $file->moveToNewName($this->dir . '/solo-lectura/final.txt');
    }

    public function testElArchivoTemporalDebeExistir(): void
    {
        $file = $this->file('a.txt', 'hola');
        unlink($file->getTmpName());

        $this->expectException(Exception::class);

        $file->moveToNewName($this->dir . '/final.txt');
    }

    public function testUnArchivoQueNoVieneDeUnaSubidaHttpNoSeMueve(): void
    {
        // Sin 'from' => 'formData', se usa move_uploaded_file(), que rechaza cualquier archivo que PHP
        // no haya recibido en esta petición: así no se puede "subir" un archivo del servidor
        $file = $this->file('a.txt', 'hola', 'other');

        $this->expectException(Exception::class);

        $file->moveToNewName($this->dir . '/final.txt');
    }

    public function testDatosDelArchivo(): void
    {
        $file = $this->file('foto.txt', str_repeat('x', 1536));

        self::assertSame('1.5 KB', $file->getSizeForHumans());
        self::assertSame('txt', $file->getExtension());
        self::assertSame(['name', 'extension', 'size', 'type', 'tmp_name', 'from'], array_keys($file->getMetadata()));
        self::assertSame('foto.txt', $file['name']);
        self::assertTrue(isset($file['size']));

        $file['name'] = 'otro.txt';
        self::assertSame('otro.txt', $file['name']);
        unset($file['name']);
        self::assertFalse(isset($file['name']));
        self::assertSame('500 B', $this->file('b.txt', str_repeat('x', 500))->getSizeForHumans());
    }

    /** @return array<string, array{int, string}> */
    public static function erroresDeSubida(): array
    {
        return [
            'muy grande (php.ini)' => [UPLOAD_ERR_INI_SIZE, ''],
            'muy grande (formulario)' => [UPLOAD_ERR_FORM_SIZE, ''],
            'parcial' => [UPLOAD_ERR_PARTIAL, 'parcialmente'],
            'sin archivo' => [UPLOAD_ERR_NO_FILE, 'No se seleccionó'],
            'sin carpeta temporal' => [UPLOAD_ERR_NO_TMP_DIR, 'temporal'],
            'no se pudo escribir' => [UPLOAD_ERR_CANT_WRITE, 'escribir'],
        ];
    }

    #[DataProvider('erroresDeSubida')]
    public function testCadaErrorDeSubidaTieneSuMensaje(int $code, string $fragment): void
    {
        $message = (new ReflectionClass(File::class))
            ->newInstanceWithoutConstructor()
            ->isValidUpload(['error' => $code]);

        self::assertFalse($message['valid']);
        self::assertNotSame('', $message['message']);
        if ($fragment !== '') {
            self::assertStringContainsString($fragment, $message['message']);
        }
    }

    /** Un archivo como los de un formulario multipart (File copia el contenido, no move_uploaded_file) */
    private function file(string $name, string $content, string $from = 'formData'): File
    {
        $tmp = $this->dir . '/tmp-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);

        return new File([
            'name' => $name,
            'type' => 'text/plain',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
            'from' => $from,
        ]);
    }
}
