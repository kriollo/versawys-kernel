<?php

declare(strict_types=1);

// Servidor de eco para HttpClientTest: devuelve lo que recibió, o una respuesta según la ruta
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/texto') {
    header('Content-Type: text/plain');
    header('X-Prueba: si');
    echo 'hola mundo';

    return;
}

if ($path === '/error') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"success":0}';

    return;
}

if ($path === '/redirige') {
    header('Location: /texto', true, 302);

    return;
}

header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'query' => $_GET,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'body' => file_get_contents('php://input'),
    'post' => $_POST,
    'files' => array_map(static fn(array $f): array => ['name' => $f['name'], 'size' => $f['size']], $_FILES),
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'token' => $_SERVER['HTTP_X_TOKEN'] ?? '',
    'cookie' => $_SERVER['HTTP_COOKIE'] ?? '',
]);
