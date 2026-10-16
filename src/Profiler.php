<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Throwable;
use VersaORM\VersaORM;

/**
 * Profiler de desarrollo: qué hizo cada petición (tiempos, consultas, caché, cola, excepciones).
 *
 * - Solo con `build.debug` (y `profiler.enabled`, activo por defecto). En producción no corre.
 * - El panel (`/_profiler`) solo responde desde la propia máquina o desde `profiler.allowed_ips`.
 * - Guarda la petición y la respuesta (cabeceras y cuerpo, hasta 64 KB) para verlas y repetirlas en
 *   el probador de /doc, sin valores sensibles: Cookie, Authorization, el token CSRF y todo campo que
 *   parezca un secreto (password, token, api_key… como en el log) quedan como `[oculto]`; de los
 *   archivos, solo nombre, tipo y tamaño. El SQL va con sus `?` y sin los valores, de la sesión solo
 *   los nombres de las claves, de la cola solo la clase del trabajo.
 *
 * Cada petición queda en `versaWYS/cache/profiler/<id>.json`; se guardan las últimas 100.
 */
final class Profiler
{
    /** La misma consulta (mismo SQL) este número de veces o más en una petición: posible N+1 */
    public const int N_PLUS_ONE = 5;

    private const int KEEP = 100;

    private const int MAX_QUERIES = 500;

    /** Bytes que se guardan de cada cuerpo (petición y respuesta) */
    private const int MAX_BODY = 65_536;

    private const string HIDDEN = '[oculto]';

    /** Cabeceras que no se repiten: son de la conexión o las pone el navegador (fetch no deja fijarlas) */
    private const array TRANSPORT_HEADERS = [
        'host',
        'content-length',
        'connection',
        'accept-encoding',
        'cookie',
        'origin',
        'referer',
        'user-agent',
    ];

    /** @var array<string, mixed>|null null = no se está midiendo esta petición */
    private static ?array $data = null;

    private static int|float $start = 0;

    /** @var array<string, mixed>|null resumen de la petición recién cerrada, para la barra */
    private static ?array $summary = null;

    /** Lo que va saliendo por el búfer: el cuerpo de la respuesta */
    private static string $output = '';

    public static function enabled(): bool
    {
        global $config;

        return ($config['build']['debug'] ?? false) === true && ($config['profiler']['enabled'] ?? true) !== false;
    }

    public static function active(): bool
    {
        return self::$data !== null;
    }

    /**
     * Lo llama App::run: empieza a medir y, al terminar la petición, guarda el registro y agrega la
     * barra a las páginas HTML.
     */
    public static function start(): void
    {
        if (!self::enabled() || str_starts_with(self::path(), '/_profiler')) {
            return;
        }

        self::begin();
        // Las respuestas que no muestran la barra (navegación del SPA, API) la actualizan con este id
        Output::header('X-Versa-Profiler: ' . Request::id());
        ob_start(static function (string $html, int $phase): string {
            self::$output .= $html;
            if (($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0) {
                self::attachResponse(self::$output, headers_list());
            }

            return self::injectToolbar($html, self::summary());
        });
        register_shutdown_function(self::finish(...));
    }

    /** @internal Empieza el registro de la petición actual (sin búfer ni cierre automático). */
    public static function begin(): void
    {
        self::$start = hrtime(true);
        self::$data = [
            'id' => Request::id(),
            'time' => date('Y-m-d H:i:s'),
            // Para ordenar: varias peticiones caen en el mismo segundo
            'started' => microtime(true),
            'method' => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'path' => self::path(),
            // Solo los nombres: los valores pueden ser tokens o datos personales
            'query_keys' => array_keys($_GET),
            'request' => self::requestData(),
            'controller' => null,
            'spans' => [],
            'queries' => [],
            'exceptions' => [],
            'cache' => ['hits' => 0, 'misses' => 0, 'writes' => 0],
            'jobs' => [],
        ];

        VersaORM::onQuery(self::query(...));
    }

    /** Tiempo de un middleware o del controlador. */
    public static function span(string $type, string $name, float $ms): void
    {
        if (self::$data === null) {
            return;
        }

        self::$data['spans'][] = ['type' => $type, 'name' => $name, 'ms' => round($ms, 2)];
        if ($type === 'controller') {
            self::$data['controller'] = $name;
        }
    }

    /**
     * Una consulta de VersaORM. Los bindings no se guardan: solo cuántos eran.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function query(string $sql, array $bindings, float $ms, ?Throwable $error = null): void
    {
        if (self::$data === null || count(self::$data['queries']) >= self::MAX_QUERIES) {
            return;
        }

        self::$data['queries'][] = [
            'sql' => $sql,
            'params' => count($bindings),
            'ms' => round($ms, 2),
            'error' => $error?->getMessage(),
        ];
    }

    public static function exception(Throwable $e): void
    {
        if (self::$data === null) {
            return;
        }

        self::$data['exceptions'][] = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => str_replace(getcwd() . '/', '', $e->getFile()),
            'line' => $e->getLine(),
        ];
    }

    /** @param 'hits'|'misses'|'writes' $event */
    public static function cache(string $event): void
    {
        if (self::$data !== null) {
            self::$data['cache'][$event]++;
        }
    }

    public static function job(string $job, string $queue): void
    {
        if (self::$data !== null) {
            self::$data['jobs'][] = ['job' => $job, 'queue' => $queue];
        }
    }

    /**
     * Cierra el registro: totales, N+1, sesión (solo nombres de claves) y lo guarda en disco.
     *
     * @return array<string, mixed>|null el registro guardado
     */
    public static function finish(): ?array
    {
        if (self::$data === null) {
            return null;
        }

        VersaORM::onQuery(null);

        $data = self::$data;
        $status = http_response_code();
        $data['status'] = is_int($status) && $status > 0 ? $status : 200;
        $data['ms'] = round((hrtime(true) - self::$start) / 1e6, 2);
        $data['memory_mb'] = round(memory_get_peak_usage(true) / 1_048_576, 1);
        $data['session_keys'] = session_status() === PHP_SESSION_ACTIVE ? array_keys($_SESSION) : [];
        $data['db_ms'] = round(array_sum(array_column($data['queries'], 'ms')), 2);
        $data['n_plus_one'] = self::nPlusOne($data['queries']);
        self::$data = null;
        self::$summary = self::summaryOf($data);

        try {
            self::store($data);
        } catch (Throwable) { // @mago-expect lint:no-empty-catch-clause
            // El profiler nunca puede romper la petición
        }

        return $data;
    }

    /**
     * Consultas con el mismo SQL repetidas N_PLUS_ONE veces o más.
     *
     * @param list<array{sql: string}> $queries
     *
     * @return array<string, int> sql => veces
     */
    public static function nPlusOne(array $queries): array
    {
        $counts = array_count_values(array_column($queries, 'sql'));
        arsort($counts);

        return array_filter($counts, static fn(int $times): bool => $times >= self::N_PLUS_ONE);
    }

    /**
     * Agrega la barra antes de `</body>` si la respuesta es HTML. JSON, archivos y fragmentos del SPA
     * quedan intactos.
     *
     * @param array<string, mixed>|null $summary
     */
    public static function injectToolbar(string $html, ?array $summary): string
    {
        $position = strripos($html, '</body>');
        if ($summary === null || $position === false || !self::isHtmlResponse()) {
            return $html;
        }

        return substr($html, 0, $position) . self::toolbar($summary) . substr($html, $position);
    }

    /**
     * ¿Puede ver el panel quien hace la petición? Solo desde la propia máquina o desde profiler.allowed_ips.
     */
    public static function allowed(string $ip): bool
    {
        global $config;

        $allowed = ['127.0.0.1', '::1', ...array_map(strval(...), (array) ($config['profiler']['allowed_ips'] ?? []))];

        return self::enabled() && in_array($ip, $allowed, true);
    }

    /**
     * El panel: la lista de peticiones, o el detalle de una.
     */
    public static function panel(?string $id): string
    {
        if ($id === null) {
            return self::page('Profiler', self::listHtml(self::recent()));
        }

        $data = self::load($id);

        return $data === null
            ? self::page(
                'Profiler',
                '<p>No existe el registro ' . self::h($id) . '.</p><p><a href="/_profiler">Volver</a></p>',
            )
            : self::page($data['method'] . ' ' . $data['path'], self::detailHtml($data));
    }

    /**
     * El resumen de un registro (lo que muestra la barra), para /_profiler/<id>.json.
     *
     * @return array<string, mixed>|null
     */
    public static function summaryFor(string $id): ?array
    {
        $data = self::load($id);

        return $data === null ? null : self::summaryOf($data);
    }

    /**
     * La petición de un registro, lista para el probador de /doc (`/_profiler/<id>.request.json`):
     * método, URL con su query, cabeceras y cuerpo. Sin las cabeceras ocultas ni las de la conexión.
     *
     * @return array{method: string, url: string, headers: array<string, string>, content_type: string, body: string, files: list<array<string, mixed>>}|null
     */
    public static function replayFor(string $id): ?array
    {
        $data = self::load($id);
        $request = is_array($data) ? $data['request'] ?? null : null;
        if (!is_array($request)) {
            return null;
        }

        $headers = array_filter(
            (array) $request['headers'],
            static fn(mixed $value, string|int $name): bool => (
                $value !== self::HIDDEN
                && !in_array(strtolower((string) $name), [...self::TRANSPORT_HEADERS, 'content-type'], true)
                && !str_starts_with(strtolower((string) $name), 'sec-')
            ),
            ARRAY_FILTER_USE_BOTH,
        );
        $query = (array) $request['query'];

        return [
            'method' => (string) $data['method'],
            'url' => $data['path'] . ($query === [] ? '' : '?' . http_build_query($query)),
            'headers' => array_map(strval(...), $headers),
            'content_type' => (string) $request['content_type'],
            'body' => (string) $request['body'],
            'files' => array_values((array) $request['files']),
        ];
    }

    /**
     * Agrega al registro de la petición actual lo que respondió: cabeceras y cuerpo.
     *
     * @internal Lo llama el búfer de start() al cerrarse
     *
     * @param list<string> $headers como headers_list()
     */
    public static function attachResponse(string $body, array $headers): void
    {
        $data = isset(self::$summary['id']) ? self::load((string) self::$summary['id']) : null;
        if ($data === null) {
            return;
        }

        $list = [];
        $contentType = '';
        foreach ($headers as $line) {
            [$name, $value] = array_map(trim(...), explode(':', $line, 2)) + [1 => ''];
            // De una cookie, solo su nombre
            $value = strcasecmp($name, 'Set-Cookie') === 0 ? strtok($value, '=') . '=' . self::HIDDEN : $value;
            $list[] = [$name, $value];
            $contentType = strcasecmp($name, 'Content-Type') === 0 ? strtolower($value) : $contentType;
        }

        $data['response'] = [
            'headers' => $list,
            'content_type' => $contentType,
            'body' => self::bodyText($contentType, $body, []),
        ];

        try {
            self::store($data);
        } catch (Throwable) { // @mago-expect lint:no-empty-catch-clause
            // El profiler nunca puede romper la petición
        }
    }

    /** @internal Solo para pruebas. */
    public static function reset(): void
    {
        self::$data = null;
        self::$summary = null;
        self::$output = '';
    }

    /** @internal */
    public static function dir(): string
    {
        global $config;

        return rtrim((string) ($config['profiler']['dir'] ?? 'versaWYS/cache/profiler'), '/') . '/';
    }

    /**
     * Cabeceras, query y cuerpo de la petición actual, con los secretos ocultos.
     *
     * @return array{headers: array<string, string>, query: array<array-key, mixed>, content_type: string, body: string, files: list<array{field: string, name: string, type: string, size: int}>}
     */
    private static function requestData(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            $name = str_starts_with($key, 'HTTP_') ? substr($key, 5) : $key;
            if (!str_starts_with($key, 'HTTP_') && !in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
            $headers[$name] = self::isSecret($name) ? self::HIDDEN : (string) $value;
        }

        $files = [];
        foreach ($_FILES as $field => $file) {
            // Un campo simple o uno múltiple (fotos[])
            foreach ((array) ($file['name'] ?? []) as $i => $name) {
                $files[] = [
                    'field' => $field,
                    'name' => $name,
                    'type' => is_array($file['type'] ?? null) ? $file['type'][$i] ?? '' : $file['type'] ?? '',
                    'size' => (int) (is_array($file['size'] ?? null) ? $file['size'][$i] ?? 0 : $file['size'] ?? 0),
                ];
            }
        }

        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        $request = $GLOBALS['request'] ?? null;
        $raw = $request instanceof Request ? (string) $request->getRawBody() : '';

        return [
            'headers' => $headers,
            'query' => Loggin::redact($_GET),
            'content_type' => $contentType,
            'body' => self::bodyText($contentType, $raw, $_POST),
            'files' => $files,
        ];
    }

    /** ¿El nombre de la cabecera o del campo es de un secreto? El mismo criterio que el log */
    private static function isSecret(string $name): bool
    {
        return Loggin::redact([$name => true])[$name] !== true;
    }

    /**
     * Un cuerpo legible y sin secretos: JSON y formularios con sus campos sensibles ocultos; otro
     * texto tal cual, hasta MAX_BODY; un binario, solo su tamaño.
     *
     * @param array<array-key, mixed> $fields los campos de un formulario multipart ($_POST)
     */
    private static function bodyText(string $contentType, string $raw, array $fields): string
    {
        if (str_starts_with($contentType, 'multipart/form-data')) {
            return self::clip((string) json_encode(
                Loggin::redact($fields),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

        if (str_contains($contentType, 'json')) {
            $data = json_decode($raw, true);

            return self::clip(
                is_array($data)
                    ? (string) json_encode(
                        Loggin::redact($data),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    )
                    : $raw,
            );
        }

        if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $form);

            return self::clip(http_build_query(Loggin::redact($form)));
        }

        return self::clip($raw);
    }

    private static function clip(string $text): string
    {
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
            return '[contenido binario, ' . strlen($text) . ' bytes]';
        }

        return strlen($text) > self::MAX_BODY
            ? mb_strcut($text, 0, self::MAX_BODY) . "\n… [recortado: " . strlen($text) . ' bytes en total]'
            : $text;
    }

    /** @return array<string, mixed>|null */
    private static function summary(): ?array
    {
        // El búfer se cierra después de las funciones de cierre: finish() ya armó el resumen
        return self::$summary;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function summaryOf(array $data): array
    {
        return [
            'id' => $data['id'],
            'status' => $data['status'],
            'ms' => $data['ms'],
            'queries' => count($data['queries']),
            'db_ms' => $data['db_ms'],
            'n_plus_one' => count($data['n_plus_one']),
            'memory_mb' => $data['memory_mb'],
            // Sin el namespace: es lo que cabe en la barra
            'controller' => $data['controller'] === null ? null : self::shortName((string) $data['controller']),
            'exceptions' => count($data['exceptions']),
        ];
    }

    private static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function isHtmlResponse(): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }

        // Sin Content-Type, PHP envía text/html
        return true;
    }

    /** @param array<string, mixed> $data */
    private static function store(array $data): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            return;
        }

        file_put_contents(
            $dir . self::safeId((string) $data['id']) . '.json',
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );

        $files = self::glob($dir . '*.json');
        if (count($files) <= self::KEEP) {
            return;
        }

        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, self::KEEP) as $old) {
            unlink($old);
        }
    }

    /** @return array<string, mixed>|null */
    private static function load(string $id): ?array
    {
        $file = self::dir() . self::safeId($id) . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }

    /** @return list<array<string, mixed>> las peticiones guardadas, de la más nueva a la más vieja */
    private static function recent(): array
    {
        $out = [];
        foreach (self::glob(self::dir() . '*.json') as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $out[] = $data;
            }
        }

        // Los registros anteriores a `started` se ordenan por su hora
        $order = static fn(array $r): float => (float) ($r['started'] ?? strtotime((string) ($r['time'] ?? '')));
        usort($out, static fn(array $a, array $b): int => $order($b) <=> $order($a));

        return $out;
    }

    /** El id viene de la URL del panel: solo letras, números, punto, guion y guion bajo */
    private static function safeId(string $id): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '', $id);
    }

    /** @return list<string> */
    private static function glob(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }

    /** @param array<array-key, mixed> $names */
    private static function names(array $names): string
    {
        return $names === [] ? '—' : self::h(implode(', ', array_map(strval(...), $names)));
    }

    private static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * La barra: el resumen inicial va en JSON y el mismo script la dibuja y la actualiza. En cada
     * navegación del SPA (fetch con X-SPA) lee el id de X-Versa-Profiler y pide su resumen a
     * /_profiler/<id>.json; las demás llamadas a la API solo se cuentan.
     *
     * @param array<string, mixed> $s
     */
    private static function toolbar(array $s): string
    {
        global $config;

        $nonce = self::h(SecurityUtils::cspNonce());
        $initial = json_encode(
            $s,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
        );
        $framework = json_encode(
            (string) ($config['framework'] ?? 'versaWYS-PHP'),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );

        // La misma franja roja de modo debug que dibujaba versaTwig, ahora con las métricas del profiler
        return (
            '<style nonce="'
            . $nonce
            . '">'
            . '#versa-profiler{position:fixed;left:0;right:0;bottom:0;z-index:2147483647;display:flex;align-items:center;'
            . 'justify-content:space-between;gap:12px;flex-wrap:wrap;padding:6px 12px;background:#7f1d1d;color:#fff;'
            . 'font:12px/1.4 system-ui,sans-serif;box-shadow:0 -2px 8px rgba(0,0,0,.25)}'
            . '#versa-profiler a{color:inherit;text-decoration:none;font-family:ui-monospace,monospace}#versa-profiler a:hover{text-decoration:underline}'
            . '#versa-profiler .vp-warn{color:#fde68a}#versa-profiler .vp-api{opacity:.8}'
            . '#versa-profiler .vp-brand{display:flex;align-items:center;gap:6px}#versa-profiler img{width:20px;height:20px}'
            // En pantallas angostas, una sola línea con las métricas: tres líneas tapaban el contenido
            . '@media (max-width:640px){#versa-profiler{flex-wrap:nowrap;padding:4px 8px;font-size:11px}'
            . '#versa-profiler .vp-label,#versa-profiler .vp-brand{display:none}'
            . '#vp-metrics{min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}}'
            // Espacio al final de la página para que la barra fija no tape lo último
            . '#versa-profiler-spacer{height:44px}'
            . '</style><div id="versa-profiler-spacer"></div><div id="versa-profiler"><span class="vp-label">Modo Debug: Activado</span><span id="vp-metrics"></span>'
            . '<span class="vp-brand"><img src="/public/dashboard/img/favicon.webp" alt="">VersaWYS Framework <span id="vp-load"></span></span></div>'
            . '<script nonce="'
            . $nonce
            . '">(function(){'
            . 'var bar=document.getElementById("vp-metrics"),current='
            . $initial
            . ',api=0;'
            . 'console.log("%c"+'
            . $framework
            . ',"color:#fff;background:#f00;padding:5px;border-radius:5px");'
            . 'function add(parent,tag,text,cls){var e=document.createElement(tag);e.textContent=text;if(cls)e.className=cls;parent.appendChild(e);return e;}'
            . 'function render(s){bar.textContent="";var a=add(bar,"a","");a.href="/_profiler/"+encodeURIComponent(s.id);a.target="_blank";a.rel="noopener";'
            . 'a.textContent=s.status+" · "+s.ms+" ms · "+s.queries+" SQL ("+s.db_ms+" ms) · "+s.memory_mb+" MB"+(s.controller?" · "+s.controller:"");'
            . 'if(s.n_plus_one>0)add(a,"b"," ⚠ N+1 ×"+s.n_plus_one,"vp-warn");if(s.exceptions>0)add(a,"b"," ⚠ "+s.exceptions+" excepción(es)","vp-warn");'
            . 'if(api>0){var l=add(bar,"a"," · +"+api+" API","vp-api");l.href="/_profiler";l.target="_blank";l.rel="noopener";}}'
            . 'function load(id,retry){fetch("/_profiler/"+encodeURIComponent(id)+".json").then(function(r){if(r.ok)return r.json();'
            . 'if(retry)setTimeout(function(){load(id,false);},300);return null;}).then(function(s){if(s){current=s;render(s);}}).catch(function(){});}'
            . 'function loadTime(seconds){document.getElementById("vp-load").textContent="· CARGA: "+seconds.toFixed(2)+"s";}'
            . 'var original=window.fetch;window.fetch=function(input,init){var t0=performance.now();return original.apply(this,arguments).then(function(res){'
            . 'var id=res.headers.get("X-Versa-Profiler");if(id){var h=new Headers(init&&init.headers?init.headers:(input instanceof Request?input.headers:undefined));'
            // En una navegación del SPA, CARGA es lo que tardó esa página
            . 'if(h.get("X-SPA")){api=0;loadTime((performance.now()-t0)/1000);load(id,true);}else{api++;render(current);}}return res;});};'
            // Tiempo de carga en el navegador, desde el inicio de la navegación
            . 'window.addEventListener("load",function(){loadTime(performance.now()/1000);});'
            . 'render(current);})();</script>'
        );
    }

    private static function shortName(string $callable): string
    {
        $pos = strrpos($callable, '\\');

        return $pos === false ? $callable : substr($callable, $pos + 1);
    }

    /** @param list<array<string, mixed>> $rows */
    private static function listHtml(array $rows): string
    {
        if ($rows === []) {
            return '<p>Todavía no hay peticiones registradas. Navega por la aplicación con <code>build.debug</code> activo.</p>';
        }

        $html =
            '<table><thead><tr><th>Hora</th><th>Método</th><th>Ruta</th><th>Estado</th><th>ms</th>'
            . '<th>SQL</th><th>Memoria</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $warn = count((array) ($r['n_plus_one'] ?? [])) > 0 || count((array) ($r['exceptions'] ?? [])) > 0;
            $html .= sprintf(
                '<tr%s><td>%s</td><td>%s</td><td><a href="/_profiler/%s">%s</a></td><td>%s</td><td>%s</td><td>%d</td><td>%s MB</td><td>%s</td></tr>',
                (int) ($r['status'] ?? 200) >= 400 ? ' class="err"' : '',
                self::h(substr((string) $r['time'], 11)),
                self::h($r['method']),
                self::h($r['id']),
                self::h($r['path']),
                self::h($r['status'] ?? ''),
                self::h($r['ms'] ?? ''),
                count((array) ($r['queries'] ?? [])),
                self::h($r['memory_mb'] ?? ''),
                $warn ? '⚠' : '',
            );
        }

        return $html . '</tbody></table>';
    }

    /** @param array<string, mixed> $d */
    private static function detailHtml(array $d): string
    {
        $html =
            '<p><a href="/_profiler">← Todas las peticiones</a></p><dl>'
            . '<dt>Estado</dt><dd>'
            . self::h($d['status'])
            . '</dd>'
            . '<dt>Tiempo total</dt><dd>'
            . self::h($d['ms'])
            . ' ms (base de datos '
            . self::h($d['db_ms'])
            . ' ms)</dd>'
            . '<dt>Memoria máxima</dt><dd>'
            . self::h($d['memory_mb'])
            . ' MB</dd>'
            . '<dt>Controlador</dt><dd>'
            . self::h($d['controller'] ?? '—')
            . '</dd>'
            . '<dt>Parámetros de la URL</dt><dd>'
            . self::names((array) $d['query_keys'])
            . '</dd>'
            . '<dt>Sesión (claves)</dt><dd>'
            . self::names((array) $d['session_keys'])
            . '</dd>'
            . '<dt>Caché</dt><dd>'
            . (int) $d['cache']['hits']
            . ' aciertos, '
            . (int) $d['cache']['misses']
            . ' fallos, '
            . (int) $d['cache']['writes']
            . ' escrituras</dd>'
            . '<dt>Id</dt><dd>'
            . self::h($d['id'])
            . '</dd></dl>';

        $html .= self::exchangeHtml($d);

        if ($d['exceptions'] !== []) {
            $html .= '<h2>Excepciones</h2><ul>';
            foreach ($d['exceptions'] as $e) {
                $html .=
                    '<li class="err"><b>'
                    . self::h($e['class'])
                    . '</b>: '
                    . self::h($e['message'])
                    . ' <small>'
                    . self::h($e['file'] . ':' . $e['line'])
                    . '</small></li>';
            }

            $html .= '</ul>';
        }

        $html .= '<h2>Tiempos</h2><table><tr><th>Tipo</th><th>Nombre</th><th>ms</th></tr>';
        foreach ($d['spans'] as $s) {
            $html .=
                '<tr><td>'
                . self::h($s['type'])
                . '</td><td>'
                . self::h($s['name'])
                . '</td><td>'
                . self::h($s['ms'])
                . '</td></tr>';
        }

        $html .= '</table>';

        if ($d['n_plus_one'] !== []) {
            $html .= '<h2 class="warn">Posible N+1</h2><p>La misma consulta se ejecutó muchas veces: suele resolverse con un JOIN, un <code>WHERE id IN (...)</code> o cargando la relación una vez.</p><ul>';
            foreach ($d['n_plus_one'] as $sql => $times) {
                $html .= '<li><b>×' . self::h($times) . '</b> <code>' . self::h($sql) . '</code></li>';
            }

            $html .= '</ul>';
        }

        $html .=
            '<h2>Consultas ('
            . count($d['queries'])
            . ')</h2><table><tr><th>#</th><th>SQL</th><th>Parámetros</th><th>ms</th></tr>';
        foreach ($d['queries'] as $i => $q) {
            $html .=
                '<tr'
                . ($q['error'] !== null ? ' class="err"' : '')
                . '><td>'
                . ($i + 1)
                . '</td><td><code>'
                . self::h($q['sql'])
                . '</code>'
                . ($q['error'] !== null ? '<br><small>' . self::h($q['error']) . '</small>' : '')
                . '</td><td>'
                . self::h($q['params'])
                . '</td><td>'
                . self::h($q['ms'])
                . '</td></tr>';
        }

        $html .= '</table>';

        if ($d['jobs'] !== []) {
            $html .= '<h2>Trabajos encolados</h2><ul>';
            foreach ($d['jobs'] as $j) {
                $html .= '<li>' . self::h($j['job']) . ' <small>(' . self::h($j['queue']) . ')</small></li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * La petición y la respuesta, como un inspector de tráfico, con el botón para repetirla en el
     * probador de /doc.
     *
     * @param array<string, mixed> $d
     */
    private static function exchangeHtml(array $d): string
    {
        $request = $d['request'] ?? null;
        if (!is_array($request)) {
            return '';
        }

        $query = (array) $request['query'];
        $url = $d['path'] . ($query === [] ? '' : '?' . http_build_query($query));
        $html =
            '<h2>Petición</h2><p><code>'
            . self::h($d['method'] . ' ' . $url)
            . '</code> <a class="btn" href="/doc?route=all&amp;replay='
            . rawurlencode((string) $d['id'])
            . '">Repetir en el probador</a></p>'
            . self::headersHtml(array_map(
                null,
                array_keys((array) $request['headers']),
                array_values((array) $request['headers']),
            ));

        $html .= self::bodyHtml((string) $request['body'], 'La petición no trae cuerpo.');
        if ($request['files'] !== []) {
            $html .= '<p>Archivos (no se guardan; al repetir hay que elegirlos de nuevo):</p><ul>';
            foreach ((array) $request['files'] as $f) {
                $html .=
                    '<li><b>'
                    . self::h($f['field'])
                    . '</b>: '
                    . self::h($f['name'])
                    . ' <small>('
                    . self::h($f['type'])
                    . ', '
                    . self::h($f['size'])
                    . ' bytes)</small></li>';
            }

            $html .= '</ul>';
        }

        $response = $d['response'] ?? null;
        if (!is_array($response)) {
            return $html . '<h2>Respuesta</h2><p>No quedó registrada (la petición terminó antes de responder).</p>';
        }

        return (
            $html
            . '<h2>Respuesta</h2><p><code>'
            . self::h($d['status'])
            . '</code></p>'
            . self::headersHtml((array) $response['headers'])
            . self::bodyHtml((string) $response['body'], 'La respuesta no trae cuerpo.')
        );
    }

    /** @param array<array-key, mixed> $headers pares [nombre, valor] */
    private static function headersHtml(array $headers): string
    {
        if ($headers === []) {
            return '';
        }

        $html = '<details><summary>Cabeceras (' . count($headers) . ')</summary><table>';
        foreach ($headers as $pair) {
            $pair = (array) $pair;
            $html .=
                '<tr><th>'
                . self::h($pair[0] ?? '')
                . '</th><td><code>'
                . self::h($pair[1] ?? '')
                . '</code></td></tr>';
        }

        return $html . '</table></details>';
    }

    private static function bodyHtml(string $body, string $empty): string
    {
        return $body === '' ? '<p><small>' . self::h($empty) . '</small></p>' : '<pre>' . self::h($body) . '</pre>';
    }

    private static function page(string $title, string $body): string
    {
        $nonce = self::h(SecurityUtils::cspNonce());

        return (
            '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>'
            . self::h($title)
            . ' · Profiler</title><style nonce="'
            . $nonce
            . '">'
            . 'body{margin:0;font:14px/1.5 system-ui,sans-serif;background:#f8fafc;color:#0f172a}header{background:#0f172a;color:#e2e8f0;padding:16px 24px}'
            . 'h1{margin:0;font-size:18px;word-break:break-all}main{padding:16px 24px;max-width:1200px}h2{font-size:16px;margin-top:24px}'
            . 'table{border-collapse:collapse;width:100%;background:#fff}th,td{border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top}'
            . 'code{font:12px/1.5 ui-monospace,monospace;word-break:break-all}.err{background:#fee2e2}.warn{color:#b45309}'
            . 'dl{display:grid;grid-template-columns:max-content 1fr;gap:4px 16px}dt{color:#64748b}dd{margin:0}a{color:#2563eb}'
            . 'pre{background:#fff;border:1px solid #e2e8f0;padding:10px;overflow:auto;max-height:420px;font:12px/1.5 ui-monospace,monospace;white-space:pre-wrap;word-break:break-all}'
            . 'details summary{cursor:pointer;margin:6px 0}.btn{display:inline-block;margin-left:8px;padding:4px 10px;border-radius:6px;background:#2563eb;color:#fff;text-decoration:none;font-size:13px}'
            . '@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e2e8f0}table,pre{background:#1e293b;border-color:#334155}th,td{border-color:#334155}.err{background:#7f1d1d}a{color:#93c5fd}}'
            . '</style></head><body><header><h1>'
            . self::h($title)
            . '</h1><small>Profiler · solo en modo debug</small></header><main>'
            . $body
            . '</main></body></html>'
        );
    }
}
