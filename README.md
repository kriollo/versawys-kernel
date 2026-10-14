# versawys/kernel

Núcleo del framework [versaWYS-PHP](https://github.com/kriollo/versaWYS): enrutamiento, middlewares, sesión con JWT, protección CSRF, rate limiting, Twig, CLI (`versaCLI`) e integración con [VersaORM](https://github.com/kriollo/versaORM).

Este paquete no se usa solo: se instala dentro de un proyecto creado a partir del esqueleto [kriollo/versaWYS](https://github.com/kriollo/versaWYS), que aporta `app/`, el panel de administración y el frontend.

## Requisitos

- PHP 8.3 o superior con `intl`, `mbstring` y `pdo`
- Composer 2.2+

## Instalación en un proyecto

```bash
cd versaWYS
composer require versawys/kernel
```

`index.php` y `versaCLI` del proyecto solo cargan el autoload y llaman al kernel:

```php
require_once __DIR__ . '/versaWYS/vendor/autoload.php';
versaWYS\kernel\App::run(__DIR__);
```

## Actualizar

```bash
composer update versawys/kernel
php versaCLI app:check:deploy
```

Antes de subir de versión mayor, leer [UPGRADE.md](UPGRADE.md). Los cambios de cada versión están en [CHANGELOG.md](CHANGELOG.md).

## Desarrollo

Clonar este repositorio junto al esqueleto, con los dos en la misma carpeta:

```bash
git clone https://github.com/kriollo/versawys-kernel.git
git clone https://github.com/kriollo/versaWYS.git
cd versawys-kernel && composer install && composer check

# Probar el esqueleto contra este kernel (sin commitear el cambio en composer.json)
cd ../versaWYS
composer config repositories.kernel '{"type": "path", "url": "../versawys-kernel", "options": {"symlink": true}}'
composer require "versawys/kernel:*@dev" -W
composer test
```

La CI hace lo mismo en PHP 8.3, 8.4 y 8.5. Si el cambio también necesita modificar el esqueleto, se crea en él una rama con el mismo nombre que la del PR del kernel: la CI la usa en vez de `main`.

## Pruebas

```bash
composer test     # PHPUnit: Router, Request, archivos, rate limiting, configuración, /up
composer check    # formato + pruebas + phpstan
composer bench    # línea base de rendimiento (ver BENCHMARK.md)
```

La CI también ejecuta `composer audit` y la suite del esqueleto contra este kernel, que cubre por HTTP lo que necesita un servidor: sesión y JWT, CSRF, CSP, `QUERY`, `app:check:deploy --url`. Las pruebas nuevas que no necesitan un servidor van en este repositorio.

## Versiones y compatibilidad

- **PHP:** 8.3, 8.4 y 8.5, las tres probadas en la CI. Una versión de PHP se deja de soportar solo en una versión mayor del kernel, cuando PHP termina su soporte de seguridad.
- **SemVer:** parche = correcciones, menor = funciones nuevas compatibles, mayor = cambios incompatibles. Qué cuenta como API pública está en [API.md](API.md).
- **Deprecación:** antes de quitar algo, pasa al menos una versión menor marcado `@deprecated`, con su reemplazo en UPGRADE.md. Se elimina en la siguiente versión mayor.
- **Soporte:** la última versión mayor recibe correcciones; ver [SECURITY.md](SECURITY.md).

## Documentos

| Documento | Contenido |
|---|---|
| [API.md](API.md) | Qué es estable, qué es interno y qué está deprecado |
| [LIFECYCLE.md](LIFECYCLE.md) | Recorrido de una petición, de `index.php` a la respuesta |
| [UPGRADE.md](UPGRADE.md) | Cómo actualizar entre versiones mayores |
| [SECURITY.md](SECURITY.md) | Soporte, cómo reportar y comportamiento ante fallos |
| [THREAT_MODEL.md](THREAT_MODEL.md) | Activos, límites de confianza y mitigaciones con su prueba |
| [BENCHMARK.md](BENCHMARK.md) | Línea base de rendimiento |
| [DECISIONS.md](DECISIONS.md) | Lo que se evaluó y no se implementó, y cuándo revisarlo |
| [ROADMAP.md](ROADMAP.md) | Trabajo pendiente |
| [CHANGELOG.md](CHANGELOG.md) | Cambios de cada versión |

## Licencia

MIT
