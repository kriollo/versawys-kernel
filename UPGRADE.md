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
