# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versionado [SemVer](https://semver.org/lang/es/).

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
