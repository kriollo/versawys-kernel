<?php

declare(strict_types=1);

global $config;

// CORS: solo se responde a los orígenes listados en config `cors.allowed_origins`.
// Las peticiones same-origin no envían cabecera Origin y no necesitan CORS.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $config['cors']['allowed_origins'] ?? [], strict: true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, QUERY, OPTIONS');
    header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-CSRF-Token, X-SPA, X-HTTP-Method-Override');
    header('Access-Control-Max-Age: 86400');
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
// El mismo identificador que llevan las entradas de log de esta petición
header('X-Request-Id: ' . versaWYS\kernel\Loggin::requestId());
header('Referrer-Policy: strict-origin-when-cross-origin');
// "0" apaga el filtro XSS antiguo de los navegadores, que tenía fallas propias (recomendación de OWASP);
// la protección real es la CSP
header('X-XSS-Protection: 0');
// Qué APIs del navegador puede usar la página. Vacío en config para no enviarla
$permissionsPolicy = (string) ($config['permissions_policy'] ?? '');
if ($permissionsPolicy !== '') {
    header('Permissions-Policy: ' . $permissionsPolicy);
}
// Aísla la ventana de otros orígenes; allow-popups mantiene los popups de OAuth (Google)
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

$host = explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0];
$isLocalhost = in_array($host, ['localhost', '127.0.0.1'], strict: true);

// HSTS solo fuera de localhost para no forzar HTTPS en desarrollo
if (!$isLocalhost) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// CSP: cada directiva acepta orígenes extra desde config `csp.<directiva>` (array de strings).
// Solo en producción: en debug el HMR y la barra de depuración necesitan orígenes locales.
if (!($config['build']['debug'] ?? false) && ($config['csp']['enabled'] ?? true)) {
    $csp = $config['csp'] ?? [];
    $extra = static fn (string $directive): string => implode(' ', (array) ($csp[$directive] ?? []));

    // ponytail: 'unsafe-inline' sigue como respaldo para navegadores sin soporte de nonce.
    // csp.unsafe_eval (true si falta): Vue necesita eval solo si compila plantillas en el navegador
    // (un `template:` en texto). Con todos los componentes precompilados se apaga.
    // csp.nonce: los <script> inline solo corren si llevan el nonce de la petición (csp_nonce() en Twig,
    // <meta name="csp-nonce"> para los que crea el JavaScript). Los navegadores con soporte de nonce
    // ignoran 'unsafe-inline'; se deja como respaldo para los antiguos
    // El websocket del panel (websocket_service.ws_url) suele ir en otro puerto o dominio: su origen
    // se agrega a connect-src para que la configuración de cada servidor no tenga que repetirlo
    $ws = parse_url((string) ($config['websocket_service']['ws_url'] ?? ''));
    $wsOrigin = isset($ws['scheme'], $ws['host']) ? $ws['scheme'] . '://' . $ws['host'] . (isset($ws['port']) ? ':' . $ws['port'] : '') . ' ' : '';

    // Con nonce, 'unsafe-inline' se omite: los navegadores con nonce ya lo ignoraban, y sin él un script
    // inyectado sin nonce no corre en ninguno
    $nonce = ($csp['nonce'] ?? false) === true ? "'nonce-" . versaWYS\kernel\SecurityUtils::cspNonce() . "' " : "'unsafe-inline' ";
    $eval = ($csp['unsafe_eval'] ?? true) === true ? "'unsafe-eval' " : '';

    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' {$nonce}{$eval}{$extra('script-src')}; "
        . "style-src 'self' 'unsafe-inline' fonts.googleapis.com {$extra('style-src')}; "
        . "font-src 'self' fonts.gstatic.com {$extra('font-src')}; "
        . "img-src 'self' data: blob: https: {$extra('img-src')}; "
        . "media-src 'self' blob: data: {$extra('media-src')}; "
        . "connect-src 'self' blob: {$wsOrigin}{$extra('connect-src')}; "
        . "frame-src 'self' {$extra('frame-src')}; "
        . "frame-ancestors 'self'; "
        . "form-action 'self' {$extra('form-action')}; "
        . "base-uri 'self'",
    );
}

header_remove('Server');
header_remove('X-Powered-By');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit();
}
