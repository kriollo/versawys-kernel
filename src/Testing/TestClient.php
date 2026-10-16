<?php

declare(strict_types=1);

namespace versaWYS\kernel\Testing;

use RuntimeException;
use SensitiveParameter;
use versaWYS\kernel\Container;
use versaWYS\kernel\GlobalMiddleware;
use versaWYS\kernel\Output;
use versaWYS\kernel\Request;
use versaWYS\kernel\RequestHalted;
use versaWYS\kernel\Response;
use versaWYS\kernel\Router;
use versaWYS\kernel\SecurityUtils;
use versaWYS\kernel\Session;

/**
 * Prueba la aplicación completa en el mismo proceso, sin levantar un servidor: rutas, middlewares,
 * controladores, sesión, CSRF, base de datos y plantillas, igual que en una petición real.
 *
 *     $app = TestClient::boot(dirname(__DIR__));
 *     $app->login('admin@ejemplo.cl', 'clave');
 *     $app->postJson('/admin/tableros/api/tableros', ['nombre' => 'Demo'])->assertStatus(201);
 *     $app->get('/admin/tableros')->assertOk()->assertSee('Tableros');
 *
 * Se comporta como un navegador: guarda las cookies (incluida la de sesión) entre peticiones y manda el
 * token CSRF de la sesión en cada POST, PUT, PATCH y DELETE, como hace el panel. La protección CSRF sigue
 * activa: `withoutCsrfToken()` permite comprobar que una ruta lo exige.
 */
final class TestClient
{
    private static ?string $root = null;

    /** @var array<string, string> */
    private array $cookies = [];

    /** @var array<string, string> */
    private array $headers = [];

    private bool $sendCsrfToken = true;

    private function __construct() {}

    /**
     * Arranca la aplicación una vez por proceso: configuración, rutas, Twig y base de datos.
     *
     * @param string $root raíz del proyecto (la carpeta de index.php)
     * @param string|null $configFile otro config.json, p. ej. uno con la base de datos de pruebas
     */
    /** ¿Ya arrancó la aplicación en este proceso? (arranca una sola vez) */
    public static function booted(): bool
    {
        return self::$root !== null;
    }

    public static function boot(string $root, ?string $configFile = null): self
    {
        if (self::$root === null) {
            self::$root = $root;
            chdir($root);
            if ($configFile !== null) {
                putenv('VERSAWYS_CONFIG=' . $configFile);
            }

            // Sin navegador: el id de sesión lo maneja el cliente y PHP no debe enviar cabeceras de sesión
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');

            self::prepareServer('GET', '/', []);
            Output::capture();

            try {
                require __DIR__ . '/../config/config.php';
            } catch (RequestHalted) {
                throw new RuntimeException('La aplicación no pudo arrancar (revise config.json y la base de datos).');
            } finally {
                Output::release();
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
            }
        } elseif (self::$root !== $root) {
            throw new RuntimeException('TestClient ya arrancó con otra raíz: ' . self::$root);
        }

        return new self();
    }

    /**
     * Corre `$fn` con esas claves de config.json cambiadas y después deja la configuración como estaba,
     * aunque la prueba falle. Para probar combinaciones sin otro config.json:
     *
     *     TestClient::withConfig(['admin' => ['hide_default' => true]], function () use ($client): void {
     *         $client->get('/admin/login')->assertStatus(404);
     *     });
     *
     * Las claves se fusionan como en config.json; una lista reemplaza la original posición por
     * posición. Lo que el kernel lee al arrancar (la conexión a la base, Twig) no cambia.
     *
     * @template T
     *
     * @param array<string, mixed> $overrides
     * @param callable(): T $fn
     *
     * @return T
     */
    public static function withConfig(array $overrides, callable $fn): mixed
    {
        global $config;

        $original = $config;
        $config = array_replace_recursive($config, $overrides);

        try {
            return $fn();
        } finally {
            $config = $original;
        }
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $this->headers = $headers + $this->headers;

        return $this;
    }

    /** Las siguientes peticiones no mandan el token CSRF: para probar que una ruta lo exige. */
    public function withoutCsrfToken(): self
    {
        $this->sendCsrfToken = false;

        return $this;
    }

    /** Inicia sesión en el panel por su formulario real (con CSRF y rate limiting incluidos). */
    public function login(string $email, #[SensitiveParameter] string $password): TestResponse
    {
        $panel = '/' . trim((string) ($GLOBALS['config']['admin']['custom_url'] ?? 'admin'), '/');
        $response = $this->postJson($panel . '/login/autentication', ['email' => $email, 'password' => $password]);
        if ($response->status !== 200 || ($response->json('success') ?? 0) !== 1) {
            throw new RuntimeException('No se pudo iniciar sesión como ' . $email . ': ' . $response->body);
        }

        return $response;
    }

    /** @param array<string, mixed> $query */
    public function get(string $uri, array $query = []): TestResponse
    {
        return $this->request('GET', $uri, $query);
    }

    /** @param array<string, mixed> $query */
    public function getJson(string $uri, array $query = []): TestResponse
    {
        return $this->request('GET', $uri, $query, ['Accept' => 'application/json']);
    }

    /** @param array<string, mixed> $data */
    public function post(string $uri, array $data = []): TestResponse
    {
        return $this->request('POST', $uri, $data);
    }

    /**
     * Un formulario con archivos (multipart), como un `<input type="file">`. Cada archivo se copia a un
     * temporal: la prueba puede usar el mismo varias veces.
     *
     *     $client->postFile('/admin/usuarios/updateAvatar', [], ['fileAvatar' => __DIR__ . '/fixtures/foto.png']);
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $files campo => ruta del archivo
     */
    public function postFile(string $uri, array $data, array $files): TestResponse
    {
        $uploads = [];
        foreach ($files as $field => $path) {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'versa_upload_');
            copy($path, $tmp);
            $uploads[$field] = [
                'name' => basename($path),
                'type' => (string) mime_content_type($path),
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => (int) filesize($tmp),
                // move_uploaded_file() solo acepta subidas HTTP reales: File copia el contenido, como
                // con los archivos de un PUT multipart
                'from' => 'formData',
            ];
        }

        return $this->request('POST', $uri, $data, ['Content-Type' => 'multipart/form-data'], files: $uploads);
    }

    /** @param array<string, mixed> $data */
    public function postJson(string $uri, array $data = []): TestResponse
    {
        return $this->json('POST', $uri, $data);
    }

    /** @param array<string, mixed> $data */
    public function putJson(string $uri, array $data = []): TestResponse
    {
        return $this->json('PUT', $uri, $data);
    }

    /** @param array<string, mixed> $data */
    public function patchJson(string $uri, array $data = []): TestResponse
    {
        return $this->json('PATCH', $uri, $data);
    }

    /** @param array<string, mixed> $data */
    public function deleteJson(string $uri, array $data = []): TestResponse
    {
        return $this->json('DELETE', $uri, $data);
    }

    /** @param array<string, mixed> $data */
    public function json(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->request(
            $method,
            $uri,
            [],
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            (string) json_encode($data),
        );
    }

    /**
     * Una petición completa por el kernel: cabeceras de seguridad, rutas, middlewares y controlador.
     *
     * @param array<string, mixed> $data parámetros de la URL (GET) o del formulario (POST)
     * @param array<string, string> $headers
     * @param array<string, array<string, mixed>> $files como $_FILES (ver postFile())
     */
    public function request(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        ?string $body = null,
        array $files = [],
    ): TestResponse {
        global $config;

        $method = strtoupper($method);
        $headers += $this->headers;

        // La sesión de la petición anterior se guarda; esta sigue con la de la cookie, como un navegador
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $sessionId = $this->cookies[session_name()] ?? null;
        session_id($sessionId ?? (string) session_create_id());

        Response::resetRequestState();
        GlobalMiddleware::resetRequestState();
        Session::resetRequestState();
        SecurityUtils::resetRequestState();
        Request::resetRequestState();

        self::prepareServer($method, $uri, $headers);
        $_COOKIE = $this->cookies;
        $_FILES = $files;
        if ($method === 'GET' || $method === 'HEAD') {
            $_GET = $data + $_GET;
            $_POST = [];
        } elseif ($body === null) {
            $_POST = $data;
            $_SERVER['CONTENT_TYPE'] ??= 'application/x-www-form-urlencoded';
        }

        // Request lee los parámetros de $_REQUEST, como en una petición real
        // @mago-expect lint:no-request-variable
        $_REQUEST = $_GET + $_POST;
        Request::setInput($body ?? ($method === 'GET' ? '' : http_build_query($data)));

        Output::capture();
        ob_start();

        try {
            $GLOBALS['session'] = new Session((int) ($config['session']['lifetime'] ?? 3600));
            if ($this->sendCsrfToken && !in_array($method, ['GET', 'HEAD', 'OPTIONS', 'QUERY'], true)) {
                $token = (string) ($_SESSION['csrf_token'] ?? '');
                // Como el panel: fetch lo manda en la cabecera y un formulario, en el campo oculto _csrf_token
                $_SERVER['HTTP_X_CSRF_TOKEN'] ??= $token;
                if ($body === null) {
                    $_POST['_csrf_token'] ??= $token;
                    // @mago-expect lint:no-request-variable
                    $_REQUEST = $_GET + $_POST;
                    Request::setInput(http_build_query($_POST));
                }
            }

            $GLOBALS['request'] = new Request();

            require __DIR__ . '/../init_headers.php';
            (new Router())->resolve();

            // @mago-expect lint:no-empty-catch-clause
        } catch (RequestHalted) {
            // Fin normal de una petición que en producción termina con exit()
        } finally {
            $output = (string) ob_get_clean();
            $captured = Output::release();
            if (session_status() === PHP_SESSION_ACTIVE) {
                $this->cookies[(string) session_name()] = (string) session_id();
                session_write_close();
            }
        }

        foreach ($captured['cookies'] as $name => $cookie) {
            $expires = (int) ($cookie['expires'] ?? 0);
            if ($cookie['value'] === '' || $expires !== 0 && $expires < time()) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = (string) $cookie['value'];
            }
        }

        return new TestResponse($captured['status'], $captured['headers'], $output);
    }

    /** El contenedor de la aplicación: en pruebas, `->container()->instance(Servicio::class, $doble)`. */
    public function container(): Container
    {
        return Container::getInstance();
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** @param array<string, string> $headers */
    private static function prepareServer(string $method, string $uri, array $headers): void
    {
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        parse_str($query, $get);

        $_GET = $get;
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $query,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => (self::$root ?? getcwd()) . '/index.php',
            'DOCUMENT_ROOT' => self::$root ?? (string) getcwd(),
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'HTTP_USER_AGENT' => 'versaWYS TestClient',
            // El de un navegador: con application/json el kernel trata la petición como de API
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $_SERVER[$key] = $value;
            } else {
                $_SERVER['HTTP_' . $key] = $value;
            }
        }
    }
}
