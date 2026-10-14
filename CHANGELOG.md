# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versionado [SemVer](https://semver.org/lang/es/).

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
