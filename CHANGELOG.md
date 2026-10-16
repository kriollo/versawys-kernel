# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versionado [SemVer](https://semver.org/lang/es/).

## [Sin publicar]

Paso 8 del ROADMAP, en desarrollo. Saldrá en una sola versión al terminar.

### Añadido
- **Programador de tareas:**
  - Las tareas se definen en `app/schedule.php` con `Schedule::cron(Clase::class)` (una clase de `app/cron`) o `Schedule::command('...')`.
  - Frecuencias: `everyMinute`, `everyMinutes(n)`, `hourly(At)`, `daily(At)`, `weeklyOn`, `monthlyOn` o `cron('expresión')`.
  - `withoutOverlapping()` usa un candado de archivo: si la ejecución anterior sigue corriendo, esa vuelta se omite.
  - Comandos: `app:schedule:run` (una sola línea en el crontab; lanza cada tarea en su propio proceso), `app:schedule:list` (próxima ejecución y resultado de la última) y `app:schedule:exec <id>`.
  - `CronExpression` acepta expresiones cron estándar de 5 campos.
  - **Frecuencia en palabras** («Cada 5 minutos», «Todos los días a las 03:00»): la da `CronExpression::describe()` y la muestra el panel.
  - **La línea sugerida para el crontab** trae la ruta completa de PHP y escribe la salida en `versaWYS/logs/cron.log`. Cron corre con un `PATH` mínimo: con un PHP fuera de `/usr/bin` (cPanel, mise) el comando fallaba sin avisar, y con `/dev/null` el error se perdía.
  - `Schedule::report()` arma lo que muestran `app:schedule:list` y el panel, incluido si el crontab está llamando a `app:schedule:run`. Si no lo está, `app:schedule:list` lo avisa con la línea exacta para el crontab.

- **Instalación a prueba de un hosting sin terminal** (`Installation`):
  - **Primer arranque sin `config.json`:** se crea solo, con clave de sesión propia y token de instalación. Si el servidor no cumple los requisitos, se muestra una página con qué corregir: PHP, extensiones, carpetas con permiso de escritura y HTTPS.
  - **Instalación pendiente** (existe el token y no existe `installed.lock`): toda página lleva a `/install` y la API responde 503 en JSON.
  - **Proyectos ya instalados:** no tienen token, así que no se ven afectados.
  - `ConfigManager::createConfig()` lo comparten `config:init` y el primer arranque.
- **`SystemCheck`**: una sola revisión del servidor para el asistente de instalación y `versaCLI status`. Revisa:
  - la versión de PHP;
  - las extensiones del proyecto y las que piden las dependencias de producción, según `installed.json`, sin herramientas de desarrollo y considerando los polyfills;
  - el driver de la base de datos;
  - las carpetas con permiso de escritura;
  - el frontend compilado;
  - HTTPS, en la web.

  Cada falla dice cómo corregirla en cPanel y en Linux.

- **Generadores modernos:**
  - `versaMODULE` y `RCMD` crean un módulo **aislado por empresa desde el inicio**: migración con `id_empresa`, un modelo que filtra cada consulta por empresa, una FormRequest, un controlador con el modelo inyectado y `Tenant::id()`, rutas REST documentadas y una prueba con `TestClient` que pasa recién generada.
  - Antes, el módulo listaba los registros de todas las empresas, validaba en un middleware comentado y no tenía pruebas.
  - Comandos nuevos: `request:make`, `job:make` y `test:make`. `cron:make` ya no abre otra conexión a la base de datos.
  - Las plantillas viven en `resources/generators`.
- **`Testing\TestDatabase`:**
  - una SQLite desechable con las migraciones y los seeders del proyecto, compartida por toda la suite;
  - `user()`, `empresa()` y `login()` crean datos de prueba con correos y RUT únicos;
  - si otra prueba ya arrancó `TestClient`, usa esa misma base.
- **`TestClient::booted()`.**
- **`Testing\Factory`:**
  - `define(tabla, fn(int $n) => [...])` define los datos por defecto de una tabla;
  - `create` y `createMany` guardan registros, con `created_at` y `updated_at` automáticos;
  - las definiciones se cargan solas desde `versaWYS/tests/factories`;
  - `factory:make` crea una definición, y versaMODULE crea la del módulo.
- **`Crypt`**: cifrado autenticado (AES-256-GCM con openssl) para guardar secretos en la base de datos.
  - `encrypt` acepta textos, números y arreglos; `decrypt` lanza una excepción si el valor fue alterado o se cifró con otra clave.
  - `isEncrypted` sirve para migrar valores guardados en texto plano.
  - La clave sale de `crypt.key` (32 bytes en base64) o, si falta, de `session.key`. Cada uso tiene una clave derivada distinta.
- **`SignedUrl`**: enlaces que vencen, para descargas o confirmaciones por correo.
  - `SignedUrl::make('/ruta', segundos, [params])` firma la ruta, los parámetros y el vencimiento.
  - El middleware `[SignedUrl::class, 'check']` responde 403 si el enlace venció o fue alterado.
- **Modo mantenimiento** (`Maintenance`): `app:down` hace que todo responda 503, con `Retry-After`; la API responde en JSON. `app:up` lo termina.
  - Quien abre `/<clave>` una vez queda con una cookie firmada (no es la clave) y sigue entrando. `--secret` la elige; si no, se genera una.
  - El estado vive en `down.json`, junto a `config.json`: sobrevive a `cache:clear`.
- **`TestClient::postFile(uri, datos, [campo => ruta])`**: un formulario con archivos, para probar subidas (avatares, documentos).
- **`TestClient::withConfig(cambios, fn)`**: corre una prueba con otras claves de `config.json` y después deja la configuración como estaba.
- **Empresa suspendida** (`empresas.estado = 0`): `checkSession` cierra la sesión de sus usuarios, salvo la del superadministrador. Requiere que `Users::findWithCompany()` entregue `empresa_estado` (el esqueleto ya lo hace); sin esa clave, no se revisa.
- **El login explica por qué se cerró la sesión:** `checkSession` vuelve a `/login?motivo=cuenta_desactivada` o `?motivo=empresa_suspendida`.
- **Aviso de mantenimiento para el equipo:** la variable de Twig `maintenance` indica si el sitio está en `app:down`.

### Corregido
- **Un parámetro de ruta igual a `0` respondía 405** (`/perfiles/0`): al contar los segmentos de la URL, `array_filter()` descartaba el `"0"`.
- **Con la contraseña vencida, la API seguía respondiendo:** solo las páginas llevaban al perfil, así que el bloqueo se saltaba llamando a la API. Ahora la API responde 403 con `password_expired: true`, salvo el perfil y el cambio de contraseña.
- **Cerrar la sesión por el estado de la cuenta no revocaba el JWT:** el login veía la cookie como una sesión abierta y devolvía al panel, que la volvía a cerrar. Ahora revoca el token igual que el logout.
- **Un GET de API sin permiso (`onlyAdmin`, `onlySuperAdmin`) respondía 302 al dashboard:** un `fetch` sigue la redirección y recibía HTML con 200. Ahora responde 403 en JSON; las páginas siguen redirigiendo.
- **`Cache` en Redis mezclaba proyectos:** las claves eran `cache:<clave>`, sin identificar el proyecto. Dos sitios en el mismo Redis leían la caché del otro, y `Cache::flush()` (también `cache:clear`) borraba la de todos. Ahora cada proyecto tiene su espacio: `cache.prefix`, o uno derivado de `session.key`. Al actualizar, la caché en Redis empieza vacía.
- **Un Redis caído fallaba en silencio:** `RedisModel` atrapa los errores de conexión y devuelve `false` o el valor por defecto, pero solo los registraba con `enableLogging`, que estaba apagado. Ahora quedan en el log `RedisModel`.
- **`Loggin::request()` y `Loggin::performance()` guardaban el nivel en el lugar del mensaje:** el nivel quedaba en INFO y el texto era solo «ERROR» o «WARNING».
- **`CommandExecutor` lanzaba los procesos con `php` sin ruta:** en cron y en PHP-FPM, que corren con un `PATH` mínimo, el proceso hijo no arrancaba y nada lo avisaba. Por eso el programador decía «Lanzada», pero la tarea nunca corría. Ahora usa la ruta completa del PHP actual (`CommandExecutor::phpBinary()`). Además, la salida de cada tarea programada va a `versaWYS/logs/cron.log`.
- **`versaCLI status` pedía `tailwind.config.js`**, que Tailwind 4 ya no usa. Ahora revisa `src/dashboard/css/dashboard.css`. Sus extensiones y requisitos salen de `SystemCheck`.
- **Una instalación nueva sin `config.json` mostraba un error fatal de PHP con código 200 en cualquier página.** Con la configuración creada pero sin instalar, llevaba al login y `/install` solo era accesible con el token que imprime la terminal.
- **La barra del profiler no cambiaba al navegar en el SPA.** Ahora cada respuesta lleva `X-Versa-Profiler` y la barra pide `/_profiler/<id>.json`. CARGA mide cada página, y las demás llamadas a la API se cuentan.
- **Una sola barra de debug:** la franja roja que dibujaba `versaTwig` ahora es la del profiler. Si el profiler está apagado, `versaTwig` vuelve a dibujar la suya.

## [3.10.0] - 2026-10-15

Paso 8 del ROADMAP, ítem 1: panel de depuración y profiler.

### Añadido
- **Profiler** (solo con `build.debug`):
  - Cada página HTML muestra una barra con estado, tiempo, número y tiempo de las consultas, memoria y controlador, con aviso de N+1 y de excepciones.
  - `/_profiler` lista las últimas 100 peticiones y `/_profiler/<id>` muestra el detalle: tiempo de cada middleware y del controlador, cada consulta SQL con su duración y su error, posibles N+1 (la misma consulta 5 veces o más), excepciones, caché, claves de sesión y trabajos encolados.
  - **Acceso:** solo desde la propia máquina o desde `profiler.allowed_ips`; a cualquier otro le responde 404.
  - **Privacidad:** nunca guarda valores de consultas, de la URL ni de la sesión, ni datos de trabajos, cookies o cabeceras.
- **`versaCLI <comando>` sin `php`:**
  - El paquete trae el lanzador `bin/versaCLI`. Se instala una vez por máquina copiándolo a `~/.local/bin`, y busca el proyecto desde la carpeta actual hacia arriba.
  - El `versaCLI` del proyecto (`stubs/versaCLI`) es ejecutable, así que también funciona `./versaCLI <comando>`.

### Cambiado
- Requiere `versaorm/versaorm-php` `^2.1`, que entrega cada consulta al profiler (`VersaORM::onQuery`).



Cierre del ROADMAP: revisión final de los criterios de salida de los siete pasos, con los límites conocidos.

### Corregido
- `php versaCLI cache:clear` también vacía la caché de la aplicación (`Cache`). Antes solo limpiaba Twig y rate limiting.

### Documentación
- **ROADMAP.md:**
  - el estado al día de los pasos 3 y 4;
  - la revisión final de los criterios de salida, con evidencia por criterio;
  - los límites conocidos.
- **LIFECYCLE.md:** cómo se crean los controladores, las Form Requests, `Output` y dónde extender (validación, permisos, servicios, cola).
- **DECISIONS.md:**
  - el contenedor, `Response` sin `exit()` y los mensajes de validación, revisados por el paso 7;
  - dos decisiones nuevas: colas, caché y archivos, y el sitio de documentación.
- **UPGRADE.md:** de 3.2 a 3.9, con lo que conviene revisar.
- **SECURITY.md** y **THREAT_MODEL.md:** `Gate`, `Tenant`, `FormRequest`, `Storage`, la cola, la caché y la página de debug, con su prueba.



Fase E del paso 7 (ROADMAP.md): la página de error del modo debug.

### Cambiado
- **Error en modo debug, desde el navegador**: antes respondía un JSON crudo. Ahora muestra una página con:
  - el mensaje;
  - el código alrededor de la línea que falló y de cada archivo de la aplicación en la traza (los de `vendor/` atenuados);
  - el error SQL de VersaORM;
  - la petición, con su id.
- La página no usa Twig, por si es Twig lo que falló. Escapa todo y su `<style>` lleva el nonce de la CSP.
- Las API y el SPA siguen recibiendo JSON, ahora con `code`: el fragmento de la línea del error.
- En producción nada cambia.



Fase D del paso 7 (ROADMAP.md): caché, colas y archivos, sin dependencias nuevas.

### Añadido
- **`Cache`**: `get`, `set` (con segundos de vida), `has`, `remember`, `forget`, `flush`.
  - Por defecto en archivos (`cache.dir`); con `cache.driver: "redis"` usa RedisModel.
  - Guarda valores JSON, igual en los dos almacenes.
- **`Queue`** y la interfaz **`Job`**: trabajos en la tabla `versa_jobs`.
  - `Queue::push(Trabajo::class, $datos, delay:, tries:, queue:)`.
  - Un fallo se reintenta con espera creciente. Agotados los intentos, el trabajo queda fallido.
  - Un trabajo de un worker caído se retoma a los 10 minutos.
  - `queue.driver: "sync"` ejecuta en el acto.
  - `Queue::createTable()` define la tabla para la migración del proyecto.
- Comandos `app:queue:work` (con `--stop-when-empty` para cron), `app:queue:failed` y `app:queue:retry <id|all>`.
- **`Storage`**: archivos bajo `storage.root` (`versaWYS/storage`).
  - `put` (atómico), `get`, `exists`, `delete`, `files`, `path`.
  - Rechaza `..`, rutas absolutas y bytes nulos.
- Claves nuevas en la configuración: `cache`, `queue`, `storage` (`config:align` las agrega).



Fase C del paso 7 (ROADMAP.md): validación y autorización.

### Añadido
- **`Validator::make($datos, $reglas, $mensajes)`**: reglas como texto (`'required|max:120'`) o arreglo, con closures como reglas propias.
  - Datos anidados (`cliente.email`) y cada elemento de un arreglo (`items.*.cantidad`). El error lleva la ruta completa (`items.1.cantidad`).
  - Mensajes por `campo.regla`, por `items.*.campo.regla` o por campo.
  - `validated()` devuelve solo los campos con reglas, sin modificarlos. `Functions::validateParams()` sigue igual y usa `Validator`.
- **`FormRequest`**: un controlador que la recibe como parámetro ya tiene los datos validados; si no, responde 422 con `errors`, o 403 si `authorize()` devuelve false.
- **`Gate`**: permisos con closures o clases de política (`define('factura.anular', [PoliticaFacturas::class, 'anular'])`), `before()` para un superadministrador, `allows`/`denies`/`authorize` (403).
- **`Tenant`**: `id()` de la empresa seleccionada, `owns($fila)` y `ensure($fila)`, que responde 404 si la fila no existe o es de otra empresa.

### Corregido
- **`int:1,2,3` rechazaba cualquier valor**: comparaba el número con la lista como texto.



Fase B del paso 7 (ROADMAP.md): contenedor de dependencias optativo.

### Añadido
- **`Container`**: los controladores y middlewares se crean con él.
  - **Constructor:** los parámetros tipados con una clase se resuelven solos, recursivamente; detecta dependencias circulares y explica qué falta registrar.
  - **Métodos:** lo tipado con una clase viene del contenedor y el resto recibe los parámetros de la ruta en orden. Un `int $id` recibe el número, y si no lo es la ruta responde 404 (`RouteParameterMismatch`).
  - Un controlador sin tipos funciona igual que antes.
- Registros base: `Request` y `Session` (los de la petición), `versaTwig`/`Twig\Environment`, `Cookie`, `VersaORM`. La aplicación agrega los suyos en `app/container.php` (opcional).
- `TestClient::container()`: en pruebas, `->instance(Servicio::class, $doble)` reemplaza un servicio (p. ej. el de correo) y `forget()` lo devuelve.

### Corregido
- **`ContentType` en su propio archivo.** Estaba dentro de `MailSender.php` y solo existía si antes se había cargado `MailSender`: usarlo directamente fallaba con «Class not found». `AutoloadTest` comprueba ahora que cada archivo de `src/` declare un solo tipo, con su nombre.

## [3.5.0] - 2026-10-15

Primera fase del plan para estar al nivel de Laravel y Symfony en lo que hace seguro y productivo el trabajo diario (ver ROADMAP.md, paso 7): probar una aplicación sin levantar un servidor.

### Añadido
- **`Testing\TestClient`**: la petición recorre el kernel completo en el mismo proceso: cabeceras de seguridad, rutas, middlewares, controlador, sesión, CSRF, base de datos y plantillas.
  - Se comporta como un navegador: guarda las cookies y manda el token CSRF de la sesión (en la cabecera y en el campo `_csrf_token` de los formularios). `withoutCsrfToken()` permite probar que una ruta lo exige.
  - `login()` entra por el formulario real del panel.
  - **`Testing\TestResponse`** trae `assertStatus`, `assertOk`, `assertRedirect`, `assertSee`, `assertJson`, `assertJsonPath`, `assertHeader`.
  - En el esqueleto, 9 pruebas de la aplicación corren en 2 s.
- **`Output`**: único punto por el que el kernel escribe la respuesta (cabeceras, estado, cookies, `exit()`). En producción hace lo mismo que antes; en pruebas registra la respuesta y termina la petición con `RequestHalted` en vez de `exit()`.
- **`Request::id()`**: el identificador de la petición (`X-Request-Id`); `Loggin::requestId()` lo usa.

### Corregido
- `Request::getHeader()` fallaba con `php -S` y en consola, donde no existe `getallheaders()`: ahora lee `$_SERVER`.
- `Session` solo cambia la configuración de PHP que difiere, y regenera el id a mano si PHP no lo permite por salida previa (solo ocurre en pruebas por consola).

## [3.4.0] - 2026-10-15

### Añadido
- **`php versaCLI config:align`** lleva un `config.json` a la estructura actual del kernel sin cambiar cómo se comporta la aplicación:
  - agrega las claves que faltan con su valor por defecto, que es el que ya se estaba usando;
  - quita las que el kernel ya no lee (`build.cacheRoute*`, `security.sanitize_input`, `logging.use_redis`, `twig.auto_escape`…);
  - conserva los valores y las claves propias del proyecto, en el orden de `config.default.php`;
  - deja una copia del original (`config.json.<fecha>.bak`, permisos 600). `--dry-run` solo informa.
  - Como `config.json` no está en git, se corre en cada servidor después de desplegar.

### Cambiado
- `config:init` ya no escribe las claves obsoletas: un proyecto nuevo nace alineado.

## [3.3.3] - 2026-10-14

### Cambiado
- **`validateParams`: un campo obligatorio que falta o llega vacío dice "El campo X es obligatorio"**, en lugar de "no existe" (si no llegaba) o "es requerido" (si llegaba vacío). Si una aplicación compara el texto del mensaje, tiene que actualizarlo.

## [3.3.2] - 2026-10-14

Lo que encontró construir una aplicación nueva desde cero (`composer create-project versawys/versawys`): un tablero kanban con tareas, etiquetas y notas, generado con `versaMODULE`.

### Corregido
- **`onlyUserWithAccess` negaba la pantalla a todo usuario que no fuera admin**, aunque su perfil la tuviera en el menú: comparaba la URL completa (`/admin/tableros?x=1`) con la del menú, que se guarda relativa al panel (`/tableros`). Ahora compara la ruta relativa al panel, sin query string, con `admin` o con `admin.custom_url`. Ningún proyecto la usaba; solo el código que genera `route:make`.
- **`route:make` con un nombre en CamelCase** importaba el middleware en minúsculas (`NotasrapidasMiddleware`).
- **`versaMODULE:twig`** generaba un controlador que renderizaba `dashboard/{nombre}/index`, una plantilla que no existe; ahora usa la que crea `front:makeTwig`.
- **`--force` ahora responde que sí** a la confirmación de sobrescribir un archivo generado, como dice la ayuda.

### Cambiado
- **`versaMODULE` y `RCMD` crean también la migración**, con el mismo nombre de tabla que el modelo (`NotasRapidas` → `notas_rapidas`).
- **Lo generado funciona sin tocarlo y sigue el estilo del esqueleto:**
  - la migración crea la tabla con `id()` y `timestamps()`, y la borra en `down()`;
  - el modelo trae `all()`, y el controlador, la API `list()`;
  - la ruta queda documentada, con la pantalla en `onlyUserWithAccess` y la API en un grupo con `checkSession`;
  - el middleware trae un ejemplo con `validateParams`;
  - el componente Vue está en TypeScript, con migas de pan, y ya consulta la API.

### Desarrollo
- Pruebas nuevas: `GeneratorsTest` (lo generado es PHP válido y los nombres, la tabla y la vista calzan entre sí) y `PanelPathTest`.
- Línea base de mago: quitadas 33 entradas de hallazgos que ya no existen.

## [3.3.1] - 2026-10-14

Sin cambios de comportamiento: herramientas y formato.

### Desarrollo
- **Las mismas herramientas de calidad que los proyectos** (vservDigitalizacion, versaSTORE, anima): php-cs-fixer y rector con su misma configuración, Pint y mago. `composer fixer:all` las corre en el mismo orden que en vserv; `mago fmt` es el último paso y la CI verifica `mago fmt --dry-run` en lugar de Pint. Todo el código quedó formateado así.
- Rector no convierte métodos estáticos en métodos de instancia: en una biblioteca cambiaría cómo los usan las aplicaciones.
- Nueva prueba: cada `#[SensitiveParameter]` resuelve al atributo de PHP. Al importar nombres, rector lo dejó como `#[SensitiveParameter]` con su `use`; sin el `use`, PHP lo ignoraría y las contraseñas aparecerían en las trazas de error.
- Comprobado contra el esqueleto y los cuatro proyectos: mismas pruebas y `app:check:deploy` en verde.
- **mago** (`carthage-software/mago` 1.57) en las herramientas del kernel: `mago lint` corre en `composer check` y en la CI, con `mago.toml` y una línea base de los 159 hallazgos heredados (`mago-baseline.toml`). Un hallazgo nuevo hace fallar la CI. Revisados los de seguridad: comparaciones de tokens del analizador de PHP, `xdebug_info()` de `/up` y `$_REQUEST` en `Request` son falsos positivos y quedan en la línea base. `mago analyze` queda como `composer mago:analyze` (ver DECISIONS.md).

## [3.3.0] - 2026-10-14

Cambios a partir del primer escaneo con versaSecurity sobre el esqueleto. De sus 12 hallazgos, 4 eran falsos positivos verificados (54 "directory traversal" que eran URLs de assets en la página 404, y el "bypass" de `/admin`, que responde 302 al login); 5 eran propios de correr en local sin HTTPS (HSTS y cookies `Secure` se activan en producción). Los cambios:

### Añadido
- **`Permissions-Policy`**, configurable con `permissions_policy`. Por defecto: cámara, micrófono y ubicación solo para el propio sitio; pagos, USB, puerto serie y Bluetooth para nadie. Vacío para no enviarla.
- **`X-XSS-Protection: 0`**: apaga el filtro XSS antiguo de los navegadores, como recomienda OWASP.

### Cambiado
- **Con `csp.nonce`, `script-src` ya no incluye `'unsafe-inline'`.** Los navegadores con soporte de nonce ya lo ignoraban; ahora un script inline sin nonce no corre en ninguno. Verificado en Chrome con el panel del esqueleto: 0 bloqueos.
- **`Cookie::set()` sin `$secure` usa `session.user_cookie.secure`** (true por defecto) en vez de `false`. La cookie `expiratePass` que crean los `UsersController` de los proyectos ahora tiene `Secure` en producción.

## [3.2.1] - 2026-10-14

Ampliación de las pruebas de seguridad: de 65 a 98. Lo que encontraron:

### Seguridad
- **Detalles internos en respuestas de error.** En los proyectos hay 44 respuestas con `'errors' => $e->getMessage()` y 370 con el mensaje de una excepción en `message`; el de VersaORM trae la consulta SQL completa. En producción, un `message`, `error` o `errors` de una respuesta fallida con rastros internos (SQLSTATE, `SQL failed`, PDO, trazas, rutas del servidor) se reemplaza por "Error interno del servidor (ref. <request_id>)", y el original queda en el log `security` con la misma referencia. Los errores de validación no cambian.
- **Sesión solo por cookie:** `session.use_only_cookies` y `session.use_trans_sid` se fijan en el kernel; antes dependían del php.ini y, mal configurados, ponían el id de sesión en la URL.
- **CSRF:** una sesión sin token o un token enviado como arreglo (`_csrf_token[]=…`) terminaban en un `TypeError` y una respuesta 500; ahora son simplemente inválidos (403).

### Corregido
- `twig.auto_escape` nunca tuvo efecto: Twig llama a la opción `autoescape`. El escape HTML queda siempre activo y la clave pasa a las deprecadas (`app:check:deploy` la lista).
- `Response` no emite un aviso si falta `REQUEST_METHOD` (comandos de CLI).

### Pruebas nuevas
JWT (emisión, payload alterado, otra clave, `alg: none`, vencido, revocación, `jti` con `../`), CSRF con entradas malformadas, cookie de sesión con un php.ini inseguro, escape de Twig y `csp_nonce()`, límite de intentos por cuenta desde varias IP, rutas con `..` codificado, byte nulo y barras invertidas, y excepciones no controladas en producción (API y navegador).

## [3.2.0] - 2026-10-14

Avance del ROADMAP: pasos 1 y 2 completos, decisiones de los pasos 3 a 5 documentadas.

### Seguridad
- **Subida de archivos:** `moveToNewName()` rechaza destinos con `..`. Si una aplicación armaba la ruta con un nombre enviado por el cliente, un `../` sacaba el archivo de la carpeta prevista.
- **URL base sin `build.base_url`:** la cabecera `Host` solo se usa si es un nombre de host o una IP con puerto. Antes, cualquier valor terminaba dentro de los enlaces de los correos (por ejemplo `evil.test"><img…`).
- **URL propia del panel:** solo se reescribe el prefijo. Antes `/panel/a/panel` se convertía en `/admin/a/admin`.
- **Logs:** los valores de claves con nombre de secreto (`password`, `token`, `secret`, `api_key`, `authorization`, `cookie`, `csrf`) se reemplazan por `[oculto]` en el contexto, a cualquier profundidad. La IP de las entradas de error es la del cliente (respeta `trusted_proxies`), no la del proxy.

### Añadido
- **`request_id`** en cada entrada de log y en la cabecera `X-Request-Id`. Se respeta el que envía un proxy de `trusted_proxies`.
- **Migraciones en transacción:** cada `migrate:up` corre su migración y su registro en una transacción. En PostgreSQL, una migración que falla a la mitad ya no deja tablas creadas sin registrar (verificado: con 3.1.2 la tabla quedaba huérfana).
- **`--no-interaction` (`-n`)** en versaCLI: toda pregunta toma su valor por defecto sin leer la entrada, y una confirmación es "no".
- **Validación:** reglas `nullable` (el campo puede faltar o venir vacío), `in:a,b,c` y `boolean`.
- **`app:check:deploy` valida la configuración** contra `config.default.php`: tipos (un `"false"` en texto donde va `false` es crítico con `--production`), claves desconocidas en las secciones donde un error de tipeo desactiva una protección (`session`, `build`, `auth`, `cors`…) y claves sin efecto.
- **Rutas duplicadas:** `route:check` y `app:check:deploy` marcan como error dos declaraciones con el mismo método y URL, porque la que se carga después reemplaza a la otra sin aviso.
- `composer bench` y `BENCHMARK.md`: línea base de rendimiento repetible, con el entorno de cada medición.
- Documentos: `API.md` (qué es estable, interno o deprecado), `LIFECYCLE.md` (recorrido de una petición), `DECISIONS.md` (lo evaluado y no implementado, con su señal de revisión).
- Pruebas del kernel: de 15 a 65. Cubren Router (HEAD, 405, grupos, barras, override, panel oculto, 404 sin detalles), archivos, rate limiting (incluida concurrencia con 8 procesos), validación, configuración, contrato kernel ↔ app, logs y escape de comandos.

### Corregido
- **`required` rechazaba `0`, `"0"` y `false`** (usaba `empty()`): un estado o una cantidad en 0 no se podían enviar. Ahora solo cuentan como vacíos `null`, un texto vacío o con solo espacios y un arreglo vacío.

### Cambiado
- **`min` y `max` cuentan caracteres, no bytes.** "José" mide 4. Un texto con tildes que antes pasaba `min` contando bytes puede dejar de pasarlo.
- Un `migrate:refresh`/`dropAll` cancelado termina con código 1: un script no debe leer "éxito" si no se hizo.
- Dentro de versaCLI, los errores se muestran en la terminal según `Response::$cli` (lo activa versaCLI) y no según `PHP_SAPI`, para que las pruebas reciban la respuesta HTTP.
- `config:init` ya no genera `session.user_cookie.key_encript`.

### Deprecado (se elimina en 4.0)
- `SecurityUtils::validateCsrfToken()`: usar `Functions::validateCSRFToken()`.
- Claves de configuración sin efecto: `session.user_cookie.key_encript`, `twig.default_template_404`, `api.auth`, `rate_limiting.storage` y los restos de `build.cacheRoute*`. `app:check:deploy` las lista.

## [3.1.2] - 2026-10-14

### Seguridad
- **`trusted_proxies`:** un prefijo no numérico (`10.0.0.1/abc`) se leía como `/0` y el kernel confiaba en **todas** las direcciones de esa familia: cualquiera podía falsificar su IP con `X-Forwarded-For` y esquivar el rate limiting. Ahora las entradas inválidas se ignoran, se registran con `error_log` indicando cuál es, y `app:check:deploy` las marca (crítico con `--production`). `Request::parseProxyRange()` es la validación común.
- **`/up`** entregaba versiones de PHP y del kernel, OPcache y Xdebug a cualquier petición que llegara desde `127.0.0.1`, lo que incluye todas las visitas cuando hay un proxy en el mismo servidor. Ahora el detalle solo se entrega con la cabecera `X-Versawys-Health`, firmada con `session.key`, que envía `app:check:deploy`.
- Si no se puede escribir en `versaWYS/cache/jwt_revoked/`, el logout no revocaba el JWT y no quedaba rastro. Ahora se registra en el log `security`, y `app:check:deploy` revisa los permisos de esa carpeta.

### Añadido
- Suite de pruebas PHPUnit del kernel (`composer test`), incluida en `composer check`. Cubre proxies confiables, `/up` y el Router: el `code` de la respuesta como estado HTTP, middlewares que cortan la petición y parámetros de ruta.
- `THREAT_MODEL.md`: activos, límites de confianza, amenazas con su mitigación y su prueba, y lo que queda fuera del alcance del kernel.
- `SECURITY.md`: comportamiento ante fallos (qué pasa cuando una protección no puede funcionar) y superficie sin sesión.

### Corregido
- `Request` usa GET como método por defecto cuando `REQUEST_METHOD` no está definido.

## [3.1.1] - 2026-10-14

### Cambiado
- `route:make` (y `RCMD`, `versaMODULE`) incluye un ejemplo comentado de ruta `QUERY` para búsquedas con filtros en el cuerpo, con la forma de enviarla en desarrollo (`X-HTTP-Method-Override`).

## [3.1.0] - 2026-10-14

### Añadido
- **`csp.unsafe_eval`.** Con `false`, `script-src` deja de incluir `'unsafe-eval'`: un script inyectado ya no puede ejecutar código con `eval` ni `new Function`. Vue solo lo necesita si compila plantillas en el navegador (un `template:` en texto); los `.vue` compilados y las funciones `render` no. Por defecto `true`, así los proyectos existentes no cambian; `config:init` crea los nuevos con `false`, porque el panel base ya no compila en el navegador.

### Verificación
El panel base se recorrió con Chrome sin interfaz (login, 10 pantallas, navegación SPA, 404, selector de empresa) con nonce y sin `'unsafe-eval'`: 0 bloqueos. Con el `vue-loader` anterior, que usaba `template:`, se bloquean todas las pantallas.

## [3.0.0] - 2026-10-14

Versión mayor: quita lo que en 2.x quedó solo por compatibilidad. **−643 líneas** en `src/` y el baseline de phpstan baja de 2.959 a 2.815. Cómo actualizar: [UPGRADE.md](UPGRADE.md#de-2x-a-30).

### Eliminado
- **`security.sanitize_input`.** `getAllParams()` entrega la entrada tal como llega. El sanitizador escapaba el HTML dos veces y borraba frases que parecían SQL (`select from ...`), lo que alteraba los datos guardados sin proteger de verdad: el escape corresponde al mostrar (Twig y Vue) y el SQL va con parámetros. `app:check:deploy` avisa si la clave sigue en `config.json`.
- `route:compile`, `route:cache`, `route:clear-cache` y `cache:routes`, que no hacían nada desde 2.10.
- 22 métodos públicos sin uso en el kernel ni en los proyectos (y 9 privados que solo ellos llamaban): la cadena del sanitizador en `SecurityUtils`, los envoltorios de rate limit de `GlobalMiddleware` y otros. La lista y sus reemplazos están en UPGRADE.md.

### Sin cambios
- El contrato kernel ↔ app y la ubicación de `config.json` (incluida la ruta antigua `versaWYS/kernel/config/`).

## [2.11.1] - 2026-10-14

### Corregido
- La CSP agrega a `connect-src` el origen de `websocket_service.ws_url` (esquema, host y puerto). Cuando el websocket corría en otro puerto o dominio, el navegador bloqueaba la conexión y había que repetir esa URL en `csp.connect-src` de cada servidor. Lo encontró la prueba del inbox de versaBOT.

## [2.11.0] - 2026-10-14

### Añadido
- **Nonce de CSP** (`csp.nonce`). Cada petición genera un nonce que se agrega a `script-src`, y los `<script>` inline solo corren si lo llevan. Un script inyectado por XSS deja de ejecutarse. En las plantillas se usa `<script nonce="{{ csp_nonce() }}">`; para los scripts que crea el JavaScript, `<meta name="csp-nonce" content="{{ csp_nonce() }}">`. Apagado por defecto: los proyectos existentes no cambian. `config:init` crea los nuevos con el nonce activo, porque el panel base ya está preparado.
- `SecurityUtils::cspNonce()` y la función Twig `csp_nonce()`, que devuelve vacío si la opción está apagada; así las plantillas pueden llevarla siempre.

### Cambiado
- El HTML de error que genera el kernel usa `data-history-back` en vez de `onclick="history.go(-1)"`: con nonce, los manejadores inline quedan bloqueados.

### Verificación
El panel base se recorrió con Chrome sin interfaz por el protocolo de DevTools: login, 10 pantallas, el 404, la navegación SPA, el botón "Volver" y el selector de empresa.
- Con el nonce activo y sin adaptar las plantillas: 13 bloqueos.
- Ya adaptadas: 0 bloqueos y 0 errores de JavaScript, con el nonce activo y con el nonce apagado.

## [2.10.0] - 2026-10-14

Limpieza a partir de la auditoría de sobreingeniería: **−2.060 líneas**. El baseline de phpstan baja de 3.103 a 2.959 líneas.

### Eliminado
- **Caché de rutas.** Medido con OPcache: cargar `app/routes` tarda 308 µs y la caché concatenada 463 µs, así que no aceleraba nada y además podía servir rutas desactualizadas. Las rutas se leen siempre de `app/routes` y se ignora `build.cacheRoute`. `route:compile`, `route:cache`, `route:clear-cache` y `cache:routes` se mantienen para no romper hooks antiguos: solo avisan y borran los archivos compilados que hayan quedado. `app:deploy:finish` ya no los ejecuta.
- **`app:check:psr4 --deadcode`**, un analizador estático hecho a mano de ~760 líneas. Para métodos y variables sin uso: phpstan o mago. El chequeo PSR-4 y de mayúsculas no cambia.
- **Logs en Redis** (`logging.use_redis`) y `Loggin::stats()`: no los usaba ningún proyecto. Los logs siguen en archivo.
- **Búsqueda de `cacert.pem`** en rutas de XAMPP, WAMP y Git para Windows dentro de `Functions::httpRequest`. curl usa `curl.cainfo` y `openssl.cafile` del php.ini o los certificados del sistema (en Windows, `CURLSSLOPT_NATIVE_CA`). Probado: HTTPS con verificación funciona sin nada configurado.

### Cambiado
- Las cuatro copias de la detección de "petición API" (Session, File, versaTwig, Request) pasan a una sola, `Request::isApiRequest()`. Una navegación SPA (`X-Spa`) no cuenta como API en ningún caso, igual que en el router.
- `app:check:deploy` revisa permisos de escritura en la carpeta real de configuración (antes, `versaWYS/kernel/config` fijo).

## [2.9.0] - 2026-10-14

### Corregido
- **Validación de parámetros:**
  - `int` sin parámetro rechazaba números válidos (`"5"` daba "no es válido").
  - `integer` y `string` no existían y dejaban pasar **cualquier** valor. Ahora `integer` es un alias de `int`, `int` rechaza decimales y `string` acepta texto o números pero no arreglos ni booleanos.
  - Una regla desconocida es un error de validación: ya no deja pasar el dato en silencio.
- `app:check:psr4`, `status` y la lectura de `composer.lock` le preguntan a Composer qué `composer.json` instaló el vendor en uso. Funciona con `composer.json` en la raíz del proyecto (`composer create-project`) o en `versaWYS/`, aunque el proyecto tenga otro `composer.json` solo para scripts.
- `app:check:deploy --url` también prueba `/composer.lock`.

## [2.8.0] - 2026-10-14

### Añadido
- **`php versaCLI app:deploy:finish`**: el único hook `post_deploy` que necesita un proyecto. Aplica `versaWYS/config.compartida.json` si existe, y luego corre `migrate:up`, `cache:twig`, `route:compile`, activa la caché de plantillas, `command:refresh` y `app:check:deploy --production`. Se detiene en el primer paso que falle. Cuando el kernel agregue un paso, los `versa_deploy.yml` no cambian.
- **`php versaCLI app:kernel:publish [--force]`**: el kernel trae `index.php`, `versaCLI`, los `.htaccess` y los ejemplos de Apache y nginx. Crea los que faltan en el proyecto y muestra el diff de los que difieren; `--force` los reemplaza. `app:check:deploy` avisa si falta alguno o si difiere.

## [2.7.0] - 2026-10-14

### Añadido
- Método HTTP **`QUERY`** (IETF): `Router::query($ruta, $callback)`, una consulta segura e idempotente con cuerpo (filtros y búsquedas que no caben en la URL o no deben quedar en los logs). No exige CSRF, igual que GET. Responde con `Accept-Query`, aparece en `Allow` (405), en CORS y en `/doc`.
- `X-HTTP-Method-Override` en peticiones POST, además del campo `_method`. `php -S` (`versaCLI serve`) responde **501** a `QUERY`, así que en desarrollo, o detrás de proxies que no lo dejan pasar, se envía como POST con esa cabecera.

### Corregido
- PUT, PATCH, DELETE y QUERY ignoraban el cuerpo JSON si el `Content-Type` traía `; charset=utf-8`.

### Interno
- Las 5 copias de `get`/`post`/`put`/`patch`/`delete` pasan a un `add()` común, y las rutas se guardan sin acceso dinámico a propiedades. El baseline de phpstan baja de 3.367 a 3.103 líneas.

## [2.6.0] - 2026-10-14

### Añadido
- Ruta **`/up`** en el kernel, sin sesión, para el despliegue y los monitores. A cualquiera le responde `{"status":"ok"}`. A las peticiones del propio servidor les agrega la versión del kernel y de PHP, y el estado de OPcache y Xdebug **tal como corren en el servidor web**, que puede diferir del PHP de la terminal.
- `app:check:deploy --url` (o `--production` con `build.base_url`) consulta `/up`. Con `--production`, OPcache desactivado o Xdebug activo en el servidor web son críticos.

### Medición
Con `vendor/bin/pest --group=bench` en el esqueleto (php -S con OPcache, sin Xdebug), el p50 fue: página Twig 1,4 ms, API con sesión 1,9 ms y 404 1,1 ms. Con Xdebug activo y la caché de Twig apagada, la misma página tarda 15 ms: la configuración del servidor pesa más que cualquier optimización del kernel.

## [2.5.0] - 2026-10-14

### Añadido
- `security.sanitize_input`. Con `true` (también si falta la clave, como hasta ahora), `getAllParams()` reescribe la entrada: escapa el HTML **dos veces** (`O'Brien` se guarda como `O&amp;#39;Brien`) y borra frases que parecen SQL (`select from tabla` → `tabla`). Con `false` entrega lo que envió el cliente; el escape se hace al mostrar, porque Twig y Vue escapan por defecto.
- `config:init` crea los proyectos nuevos con `false`. `app:check:deploy` recuerda la opción cuando sigue activa.

### Para pasar un proyecto existente a `false`
Revisar que ningún `|raw` (Twig) ni `v-html` (Vue) muestre datos que escribe un usuario sin sanitizarlos. Los datos ya guardados con entidades se quedan como están.

## [2.4.1] - 2026-10-14

### Corregido
- `app:check:deploy --url`: un 200 con cuerpo vacío ya no cuenta como archivo expuesto. Los kernels anteriores a 2.1 responden así a los archivos inexistentes, y un PHP ejecutado sin salida, como `vendor/autoload.php`, tampoco expone nada.

## [2.4.0] - 2026-10-14

### Añadido
- `app:check:deploy --url=https://...`: pide al sitio real `.env`, `.git/config`, `config.json`, `composer.*`, `vendor/`, `logs/`, `versaCLI`, `node_modules/` y un archivo de `app/routes`. Si alguno responde 200, es un crítico. Con `--production` usa `build.base_url` si no se indica la URL, así que corre sola como último hook del despliegue.
- Dependabot para las dependencias y las GitHub Actions del kernel.

## [2.3.0] - 2026-10-14

### Seguridad
- `Request::getBaseUrl()` ya no acepta `X-Forwarded-Host` / `-Proto` de cualquier cliente. Antes bastaba enviarlas para que el enlace del correo de recuperación de contraseña apuntara a otro dominio. Ahora solo valen si la petición viene de `trusted_proxies`.
- `Request::getIp()` lee `X-Forwarded-For` solo desde `trusted_proxies` (IPs o rangos CIDR, IPv4 e IPv6). Así, detrás de un proxy el rate limit cuenta la IP del cliente y no la del proxy.
- Un fallo interno en una página HTML responde **500** en vez de 404, sin detalles en producción.
- Cabecera `Cross-Origin-Opener-Policy: same-origin-allow-popups`.
- `Functions::hash()` usa `PASSWORD_DEFAULT` (bcrypt con costo 12 desde PHP 8.4) y `Functions::needsRehash()` permite renovar hashes antiguos al iniciar sesión. Argon2id se descartó: no viene en todas las compilaciones de PHP.

### Añadido
- `build.base_url`: URL pública fija para los enlaces generados. `app:check:deploy` avisa si está vacía.
- `trusted_proxies` en la configuración.

### Cambios a revisar al actualizar
- Si el sitio está detrás de un proxy o balanceador que termina TLS, configurar `trusted_proxies` o `build.base_url`. Si no, los enlaces generados saldrán con `http://`.

## [2.2.0] - 2026-10-14

### Añadido
- `php versaCLI config:merge <archivo.json>`: aplica ajustes versionados en el proyecto, sin secretos (CSP, CORS, correo...), sobre el `config.json` de la máquina. Pensado como hook de despliegue: es idempotente y reemplaza las listas enteras.

## [2.1.1] - 2026-10-13

### Corregido
- Se instala en PHP 8.3, como declara: `symfony/mailer` acepta `^7.3 || ^8.1` (8.x exige PHP 8.4).

### Eliminado
- Dependencia `twig/extra-bundle`: no se usaba y arrastraba el FrameworkBundle de Symfony. Las dependencias en ejecución bajan de 41 a 21 paquetes. El filtro `inline_css` sigue disponible (`twig/cssinliner-extra`).

## [2.1.0] - 2026-10-13

Primera versión publicada como paquete de Composer. Hasta 2.0.0 el kernel vivía copiado en `versaWYS/kernel` de cada proyecto.

### Añadido
- `versaWYS\kernel\App::run()` y `CommandLineInterface::main()`: `index.php` y `versaCLI` quedan en dos líneas.
- `ConfigManager::dir()` y `ConfigManager::defaults()`.
- `app:check:deploy` revisa el contrato kernel ↔ app (métodos de `app/` que el kernel necesita).
- La versión del framework se lee de Composer (`versaCLI version`, `config.framework`).
- Compatibilidad con VersaORM `^1.9 || ^2.0`.

### Cambiado
- La configuración de proyectos nuevos vive en `versaWYS/config/`. Si existe `versaWYS/kernel/config/config.json` se sigue usando.
- Las rutas se cargan con `Router::loadRoutes()`.
- Los comandos del kernel se descubren junto a su clase, no en una ruta fija.

### Eliminado
- `kernel/autoload.php` y la función global `registerRoutes()`: Composer carga las clases.
- `cli/VersaModuleManager.php` (no tenía uso).
