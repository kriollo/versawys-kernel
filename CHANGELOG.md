# Changelog

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versionado [SemVer](https://semver.org/lang/es/).

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
