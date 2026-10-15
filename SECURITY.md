# Política de seguridad

## Versiones con soporte

| Versión | Soporte |
|---|---|
| 3.x | ✅ |
| 2.x | ❌: actualizar a 3.x (ver UPGRADE.md) |
| < 2.1 (kernel copiado) | ❌: migrar al paquete (ver UPGRADE.md) |

## Reportar una vulnerabilidad

No abrir un issue público. Usar [Report a vulnerability](https://github.com/kriollo/versawys-kernel/security/advisories/new) de GitHub (aviso privado) o escribir a jjara@wys.cl.

Incluir la versión afectada, los pasos para reproducirla y su impacto. La recepción se confirma en 72 horas. El arreglo sale como versión de parche y se anuncia en el CHANGELOG y en un aviso de seguridad de GitHub.

## Comportamiento ante fallos

Qué hace el kernel cuando una protección no puede funcionar. La regla: nunca fallar en silencio. Si una protección se desactiva en tiempo de ejecución, queda en el log `security` y `app:check:deploy --production` lo detecta antes de desplegar.

| Situación | Qué pasa en la petición | Cómo se detecta |
|---|---|---|
| Carpeta de rate limiting no escribible | La petición continúa sin contar intentos. Bloquear dejaría a todos sin poder iniciar sesión si el disco se llena. | Error en el log `security`; `app:check:deploy` falla por permisos de escritura. |
| Carpeta `versaWYS/cache/jwt_revoked/` no escribible | El logout borra la cookie, pero el token no queda revocado: si alguien lo copió, vale hasta que expire. | Error en el log `security`; `app:check:deploy` falla por permisos de escritura. |
| Entrada inválida en `trusted_proxies` | Se ignora: no se confía en esa IP ni rango. Antes de 3.1.2, un prefijo no numérico (`10.0.0.1/abc`) se leía como `/0` y confiaba en todas las direcciones de esa familia. | `error_log` con la entrada; `app:check:deploy` la marca (crítico con `--production`). |
| `config.json` sin `session.key` | Los JWT no pueden firmarse. `/up` no entrega detalle a nadie. | `app:check:deploy` (crítico con `--production`). |
| Habilidad de `Gate` no definida o política sin el método | Se niega: `allows` es false y `authorize` responde 403. | Aviso en el log `security`. |
| Trabajo de la cola que falla | Se reintenta con espera creciente. Agotados los intentos, queda fallido; nunca se descarta en silencio. | Error en el log `queue`; `php versaCLI app:queue:failed`. |
| Ruta de `Storage` con `..`, absoluta o con byte nulo | Se rechaza con `InvalidArgumentException`, sin tocar el disco. | La excepción llega al `ErrorHandler`: 500 y log. |

## Superficie expuesta sin sesión

- **`/up`.** Responde `{"status":"ok"}` a cualquiera. El detalle del entorno (versiones de PHP y del kernel, OPcache, Xdebug) solo se entrega con la cabecera `X-Versawys-Health` firmada con `session.key`, que envía `app:check:deploy`. No se decide por IP: detrás de un proxy en el mismo servidor, todas las visitas llegan desde `127.0.0.1`.
- **Login y recuperación de contraseña.** Tienen rate limiting por IP y por cuenta. Los enlaces de los correos se arman con `build.base_url`; sin ella se usa la cabecera `Host`, que el cliente controla.
