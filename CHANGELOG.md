# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versionado [SemVer](https://semver.org/lang/es/).

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
