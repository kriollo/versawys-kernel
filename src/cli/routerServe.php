<?php

declare(strict_types=1);

/**
 * Router Script para el servidor de desarrollo PHP integrado (php -S)
 * Este script maneja las reescrituras de URL y bloquea acceso a carpetas sensibles.
 */

/** Medios que el servidor de desarrollo sirve con rangos (los navegadores piden trozos al saltar) */
const VERSAWYS_MEDIA_TYPES = [
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'm4a' => 'audio/mp4',
    'ogg' => 'audio/ogg',
];

/**
 * Sirve un archivo de audio o video con soporte de un rango (Range: bytes=inicio-fin, inicio- o -sufijo).
 * Solo archivos dentro del proyecto. Responde 206 con el trozo, 200 con el archivo entero o 416.
 */
function versawys_serve_media(string $projectRoot, string $uri, string $mime): void
{
    $root = realpath($projectRoot);
    $path = realpath($projectRoot . ltrim($uri, '/'));
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        http_response_code(404);

        return;
    }

    $size = (int) filesize($path);
    $start = 0;
    $end = $size - 1;
    header('Accept-Ranges: bytes');
    header('Content-Type: ' . $mime);

    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '') {
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) !== 1 || $m[1] === '' && $m[2] === '') {
            http_response_code(416);
            header("Content-Range: bytes */{$size}");

            return;
        }

        if ($m[1] === '') {
            // Sufijo: los últimos N bytes
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }

        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */{$size}");

            return;
        }

        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . $length);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        return;
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return;
    }

    fseek($fh, $start);
    $pending = $length;
    while ($pending > 0 && !feof($fh)) {
        $chunk = fread($fh, min(1 << 16, $pending));
        if ($chunk === false) {
            break;
        }

        echo $chunk;
        $pending -= strlen($chunk);
    }

    fclose($fh);
}

// Obtener la URI solicitada
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Directorio raíz del proyecto: el que sirve `php -S -t` (el kernel puede vivir en vendor)
$projectRoot = $_SERVER['DOCUMENT_ROOT'] . '/';

// Lista de carpetas sensibles que NO deben ser accesibles directamente
$blockedDirectories = [
    '/app/',
    '/versaWYS/',
    '/vendor/',
    '/logs/',
    '/src/',
    '/configs/',
    '/scripts/',
    '/documentacion/',
];

// El frontend compilado importa Vue y otras librerías desde /node_modules/<paquete>/dist/.
// De node_modules solo se sirven esos módulos JS y CSS; nada más (package.json, fuentes, binarios).
if (
    str_starts_with(strtolower($uri), '/node_modules/')
    && preg_match('#^/node_modules/[\w@.\-/]+\.(js|mjs|css)$#i', $uri) !== 1
) {
    require_once $projectRoot . 'index.php';

    return true;
}

// Verificar si la URI comienza con una carpeta bloqueada
foreach ($blockedDirectories as $blockedDir) {
    if (!str_starts_with(strtolower($uri), strtolower($blockedDir))) {
        continue;
    }

    require_once $projectRoot . 'index.php';

    return true;
}

// Archivos y patrones que deben ser bloqueados
$blockedPatterns = [
    '/\.env',
    '/\.git',
    '/\.htaccess',
    '/composer\.json',
    '/composer\.lock',
    '/package\.json',
    '/package-lock\.json',
    '/pnpm-lock\.yaml',
    '/\.gitignore',
    '/\.editorconfig',
    '/README\.md',
];

foreach ($blockedPatterns as $pattern) {
    if (!preg_match('#' . $pattern . '#', $uri)) {
        continue;
    }

    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>';

    return true;
}

// Si el archivo existe y es un archivo estático permitido, servirlo directamente
if ($uri !== '/' && file_exists($projectRoot . $uri)) {
    // Extensiones permitidas para servir directamente
    $allowedExtensions = [
        'css',
        'js',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'svg',
        'ico',
        'webp',
        'woff',
        'woff2',
        'ttf',
        'otf',
        'eot',
        'pdf',
        'mp4',
        'webm',
        'mp3',
        'wav',
        'json',
        'xml',
        'txt',
    ];

    $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

    // Audio y video con rangos (206): el servidor integrado de PHP los ignora y responde siempre el archivo
    // entero, así el navegador no puede saltar a la mitad (un editor de video se queda cargando y el audio raspa)
    if (isset(VERSAWYS_MEDIA_TYPES[$extension])) {
        versawys_serve_media($projectRoot, $uri, VERSAWYS_MEDIA_TYPES[$extension]);

        return true;
    }

    if (in_array($extension, $allowedExtensions, true)) {
        // Dejar que PHP sirva el archivo
        return false;
    }
}

// Para todo lo demás (rutas de la aplicación, 404, etc.), pasar por el router del framework
require_once $projectRoot . 'index.php';

return true;
