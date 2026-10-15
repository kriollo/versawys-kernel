# Ciclo de vida de una petición

De `index.php` a la respuesta. Sirve para saber dónde se decide cada cosa antes de cambiarla.

## 1. Arranque — `App::run($root)`

1. `umask(0002)` y `chdir($root)`: el kernel usa rutas relativas a la raíz del proyecto.
2. **`config/config.php`** prepara el estado global que usan controladores y plantillas (`$config`, `$request`, `$session`, `$twig`, `$cookie`, `$debug`, `$versaOrm`):
   - lee `config.json` (`VERSAWYS_CONFIG`, `versaWYS/config/` o la ruta antigua `versaWYS/kernel/config/`) y lo fusiona con `config.default.php`;
   - verifica PHP ≥ 8.3 e `intl`;
   - carga las rutas de `app/routes` (`Router::loadRoutes`);
   - crea la sesión y el entorno de Twig, y lee `.env` si existe;
   - aplica el modo debug: muestra errores y deja la cookie `debug`;
   - fija zona horaria, locale y charset;
   - conecta VersaORM si hay base de datos configurada;
   - crea `$request` (Request lee la petición una sola vez: método, URL, parámetros, archivos, cuerpo y *method override*);
   - registra en el `Container` lo de la petición (`Request`, `Session`, `versaTwig`, `Cookie`, `VersaORM`) y carga `app/container.php` si existe.
3. **`init_error_handling.php`**: `ErrorHandler::initialize()` registra los manejadores de errores, excepciones y cierre.
4. **`init_headers.php`**: cabeceras de seguridad (HSTS fuera de localhost, CSP con nonce y sin eval según `csp.*`, `X-Frame-Options`, `X-XSS-Protection: 0`, `Permissions-Policy`, COOP, CORS según `cors.allowed_origins`, `X-Request-Id`). Se envían antes de resolver la ruta, así que valen también para las respuestas de error.

## 2. Resolución — `Router::resolve()`

En este orden; el primer paso que responde termina la petición:

| Paso | Si falla o coincide |
|---|---|
| Método fuera de GET, POST, PUT, PATCH, DELETE, QUERY, OPTIONS, HEAD | 405 con `Allow` |
| Rate limiting global por IP (`rate_limiting.rules.global`) | 429 |
| `GET /up` | Estado; detalle solo con firma (ver SECURITY.md) |
| `/admin/...` con `admin.hide_default` y URL propia | 404 |
| URL propia del panel (`admin.custom_url`) | Se reescribe el prefijo a `/admin/` |
| Extensión de archivo estático (`.js`, `.css`, imágenes…) | 404: un estático que llega al router no existe o está protegido |
| Primer segmento que ninguna ruta usa | 404 |
| **Coincidencia exacta** (`/admin/usuarios`) | Middlewares → controlador |
| **Coincidencia con parámetros** (`/item/{id}`), mismo número de segmentos | Middlewares → controlador, con los parámetros en orden |
| La URL existe con otro método | 405 con `Allow` |
| Nada | 404 (JSON para API, HTML para navegador, fragmento para el SPA) |

`HEAD` usa las rutas `GET` y responde sin cuerpo. `QUERY` agrega `Accept-Query` a la respuesta.

## 3. Middlewares

Primero los del grupo, después los de la ruta. Las rutas `POST`, `PUT`, `PATCH` y `DELETE` llevan además la validación CSRF al principio, salvo `->withoutCsrf()`.

- `true` → sigue con el siguiente.
- Un arreglo u objeto → se responde como JSON y la petición termina. La clave `code` es el estado HTTP (si está entre 100 y 599; si no, 200).
- Una excepción → en debug se propaga al `ErrorHandler`; en producción, 500 sin detalles.

## 4. Controlador

El `Container` crea el controlador (y cada middleware) y llama al método:

- **Constructor:** los parámetros tipados con una clase se resuelven solos; sin constructor tipado es igual que `new Controlador()`.
- **Método:** lo tipado con una clase viene del contenedor; el resto recibe los parámetros de la ruta en orden. Un `int $id` recibe el número y, si el segmento no lo es, la ruta responde 404 (`RouteParameterMismatch`).
- **`FormRequest`:** si el método la pide, se valida antes de llamarlo. Si no autoriza responde 403; si los datos fallan, 422 con `errors`. En ambos casos el método no se ejecuta.

- Devuelve texto (HTML de Twig) → se imprime.
- Devuelve un arreglo → `Response::json`, con el mismo uso de `code` que los middlewares. Los frontends que tratan 401 como "sesión vencida" dependen de esto.
- Una excepción → en debug, la página de error con el código de cada archivo de la traza (JSON con `code` para API y SPA); en producción, 500 sin SQL ni rutas (`Response::withoutInternals`).

## 5. Respuesta y errores

- `Response::json`: un warning previo no rompe la respuesta (se registra); en producción una respuesta con `success: 0` no lleva `error`, `trace`, `file` ni `line`.
- Cabeceras, estado, cookies y fin de la petición pasan por `Output`. En producción equivale a `header()`, `http_response_code()`, `setcookie()` y `exit()`. Con `Testing\TestClient`, la respuesta queda registrada y la petición termina con `RequestHalted`, sin salir del proceso: así una prueba recorre el kernel completo sin servidor.
- El estado de cada petición (Request, Response, sesión, middlewares) se reinicia con `resetRequestState()`, para que varias peticiones en un mismo proceso no se mezclen.
- Dentro de `versaCLI` (`Response::$cli`), un error se imprime en la terminal y el proceso termina con código 1.

## Dónde extender

| Necesidad | Lugar |
|---|---|
| Lógica antes de un controlador | Un middleware en `app/middleware` declarado en la ruta o en su grupo |
| Algo en todas las peticiones | Un middleware de grupo; el kernel no tiene eventos (ver DECISIONS.md) |
| Una respuesta propia | Devolver un arreglo con `code`, o usar `Response::json` / `Response::redirect` |
| Validar los datos de una acción | Una `FormRequest` como parámetro del método, o `Validator::make` |
| Quién puede hacer qué | `Gate::define` en `app/container.php`; `Tenant::ensure($fila)` para los datos de otra empresa |
| Un servicio reemplazable (correo, pagos) | Tipar el constructor y, si es una interfaz, registrarla en `app/container.php` |
| Trabajo que no debe hacer esperar al usuario | Una clase `Job` y `Queue::push`; la procesa `php versaCLI app:queue:work` |
