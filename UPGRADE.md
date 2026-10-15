# Guía de actualización

## Contrato kernel ↔ app

El panel base (`app/models`, `app/services`) se copia en cada proyecto y el kernel llama a algunos de sus métodos. La lista está en `CheckDeployCommand::APP_CONTRACT`:

| Clase | Métodos |
|---|---|
| `app\models\Users` | `findWithCompany` |
| `app\models\Empresas` | `all`, `getEmpresaById` |
| `app\models\Dashboard` | `getMenuAdmin`, `getMenuUser` |
| `app\services\MailService` | `enviarTemplate` |

**Agregar algo a esta lista o cambiar una firma es un cambio de versión mayor.** `php versaCLI app:check:deploy` falla si al proyecto le falta alguno.

## 3.2 → 3.9 (sin cambios incompatibles)

`composer update versawys/kernel` basta; el código de los proyectos no cambia. Revisar:

- **`config.json`:** hay claves nuevas, `cache`, `queue` y `storage`. Funcionan con sus valores por defecto; `php versaCLI config:align` las escribe en el archivo.
- **Controladores (3.6.0):** los crea el contenedor. Sin constructor, o con uno sin parámetros, nada cambia.
  - Un constructor con un parámetro obligatorio que no es una clase ahora falla con un mensaje claro. Antes PHP fallaba igual con `new`.
  - Un `int $id` en el método responde 404 si la URL trae algo que no es un número. Antes llegaba el texto, o PHP fallaba con un `TypeError`.
- **`int:1,2,3` (3.7.0):** antes rechazaba cualquier valor y ahora acepta los listados. Si alguna regla dependía de ese error, revisarla.
- **Modo debug (3.9.0):** un error en el navegador muestra una página HTML en vez de JSON. Las API y el SPA siguen recibiendo JSON. Producción no cambia.
- **Cola (3.8.0):** para usar `Queue`, copiar al proyecto la migración `app/migrations/m20261015120000_versa_jobs.php` del esqueleto, correr `migrate:up` y programar `php versaCLI app:queue:work --stop-when-empty` en un cron o dejar `app:queue:work` corriendo.

## 3.1 → 3.2 (sin cambios incompatibles)

- `required` acepta `0`, `"0"` y `false` como valores. Si un formulario usaba `"0"` como "sin elegir" y dependía de `required` para rechazarlo, usar `int:positive` o `in:...`.
- `min` y `max` de `validateParams` cuentan caracteres, no bytes. Revisar formularios con `min` sobre textos con tildes o `ñ`: antes podían pasar por contar de más.
- `app:check:deploy` ahora valida tipos y claves de `config.json`. En un proyecto antiguo, lo esperable es un aviso con las claves sin efecto (`key_encript`, `cacheRoute`…), que se pueden borrar.
- Para scripts y despliegues: `php versaCLI <comando> -n` nunca se queda esperando una respuesta, y un `migrate:refresh`/`dropAll` cancelado termina con código 1.

## De 2.x a 3.0

La 3.0 quita lo que en 2.x quedó solo por compatibilidad. No cambia el contrato kernel ↔ app ni la ubicación de `config.json`.

### 1. La entrada ya no se reescribe (`security.sanitize_input`)

En 2.x, con `security.sanitize_input` en `true` (el valor si la clave faltaba), `getAllParams()` escapaba el HTML de toda la entrada y borraba frases que parecían SQL. Desde 3.0 entrega lo que envió el cliente, igual que con `false`. El escape se hace al mostrar: Twig y Vue escapan por defecto, y el SQL va con parámetros.

Antes de actualizar un proyecto que tenía la opción activa:

1. Buscar dónde se muestra HTML sin escapar y comprobar que no sean datos que escribe un usuario:
   ```bash
   grep -rn '|raw' app/templates
   grep -rn 'v-html' src
   ```
   Si alguno lo es, escaparlo, o sanitizarlo como HTML (por ejemplo `Functions::sanitizeSvgContent` para SVG).
2. Buscar SQL armado con valores de la petición (`whereRaw`, `selectRaw`, `orderByRaw` o `sprintf` con `getAllParams()`) y pasar esos valores como parámetros. El sanitizador nunca protegió de verdad contra eso, pero a veces rompía un ataque por casualidad.
3. Quitar `security` de `config.json`. `app:check:deploy` avisa si la clave sigue ahí.

Los datos guardados mientras la opción estuvo activa siguen escapados (por ejemplo `O&amp;#039;Brien`). No se corrigen solos.

`getRawParams()` se mantiene y desde 3.0 es igual a `getAllParams()`.

### 2. Comandos eliminados

`route:compile`, `route:cache`, `route:clear-cache` y `cache:routes` no hacían nada desde 2.10. Ahora responden "acción no reconocida" (código 1), así que un `deploy.yml` que todavía los llame hará fallar el despliegue: quitarlos, o usar `php versaCLI app:deploy:finish`.

### 3. Métodos públicos eliminados

Ninguno se usaba en el kernel ni en los proyectos conocidos.

| Clase | Métodos | Reemplazo |
|---|---|---|
| `GlobalMiddleware` | `checkApiRateLimit`, `checkUploadRateLimit`, `checkSensitiveRateLimit` | `RateLimitMiddleware::checkApiLimit`, `checkUploadLimit`, `checkSensitiveLimit` |
| `GlobalMiddleware` | `checkPasswordExpiration` | `checkSession` ya redirige a cambiar la contraseña vencida |
| `RateLimitMiddleware` | `checkGlobalLimit` | El límite global se aplica solo en cada petición |
| `Request` | `getAccept`, `getRawBodyLength`, `validateCsrf` | `getHeader('Accept')`; el CSRF lo valida el Router |
| `SecurityUtils` | `sanitizeInputParams`, `sanitizeForJson`, `sanitizeSqlInput`, `containsSqlInjection`, `isInputSafe`, `prepareSecureJsonData`, `generateCsrfToken`, `validateCsrfForRequest` | Ver el punto 1; para CSRF, `Functions::generateCSRFToken` |
| `Session` | `checkTokenHash` | — |
| `cli\CommandExecutor` | `executeSystemCommand` | `run()` o `runInBackground()` |
| `cli\MigrationManager` | `rollbackAll` | `migrate:refresh` |
| `cli\RouteManager` | `routeCacheRemoved` | — (era el cuerpo de los comandos eliminados) |
| `helpers\Functions` | `generateHashMd5Time`, `httpPostForm` | `bin2hex(random_bytes(16))`; el cliente HTTP de `Functions` |

### 4. Actualizar

```bash
cd versaWYS && composer require versawys/kernel:^3.0 -W
php versaCLI app:check:deploy
```

## De kernel copiado (≤ 2.0) a paquete (2.1)

En la raíz del proyecto:

1. Revisar si el proyecto modificó su copia del kernel: `diff -r versaWYS/kernel <kernel 2.1>/src`. Lo que sea un arreglo general se sube al paquete; lo propio del proyecto se lleva a `app/` (una subclase o un middleware).
2. Borrar el kernel copiado, **sin tocar** `versaWYS/kernel/config/config.json` ni `installed.lock`: el kernel los sigue leyendo de ahí.
   ```bash
   find versaWYS/kernel -mindepth 1 -not -path 'versaWYS/kernel/config*' -delete
   rm -f versaWYS/kernel/config/*.php versaWYS/kernel/autoload.php
   ```
3. En `versaWYS/composer.json`: quitar `"versaWYS\\kernel\\": "../versaWYS/kernel/"` del autoload y las dependencias que ahora trae el kernel (`firebase/php-jwt`, `symfony/mailer`, `twig/*-extra`), y luego:
   ```bash
   cd versaWYS && composer require versawys/kernel:^2.1 -W
   ```
4. `php versaCLI app:kernel:publish` crea `index.php`, `versaCLI` y los `.htaccess` que falten, y muestra el diff de los que difieren. Revisarlo y usar `--force` para tomar la versión del kernel.
5. En `versa_deploy.yml`, reemplazar la lista de `post_deploy` por `php versaCLI app:deploy:finish`.
6. `php versaCLI command:refresh && php versaCLI app:check:deploy`, y correr las pruebas del proyecto.
