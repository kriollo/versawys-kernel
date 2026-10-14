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
cd ../versaWYS/versaWYS
composer config repositories.kernel '{"type": "path", "url": "../../versawys-kernel", "options": {"symlink": true}}'
composer require "versawys/kernel:*@dev" -W
composer test
```

La CI hace lo mismo en PHP 8.3, 8.4 y 8.5.

## Seguridad

Ver [SECURITY.md](SECURITY.md) y el [modelo de amenazas](THREAT_MODEL.md).

## Pruebas

```bash
composer test
composer check
```

`composer check` ejecuta formato, pruebas y análisis estático. La CI también ejecuta `composer audit` y las pruebas de integración del esqueleto versaWYS-PHP.

## Licencia

MIT
