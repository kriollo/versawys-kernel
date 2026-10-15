# Modelo de amenazas

Qué protege el kernel, de quién y dónde termina su responsabilidad. Sirve para revisar un cambio: si toca un límite de confianza de esta lista, necesita pruebas de abuso.

## Qué se protege

| Activo | Dónde vive |
|---|---|
| `session.key` (firma de JWT y de `/up`), credenciales de base de datos y SMTP | `config.json`, fuera de git y de la raíz web |
| Sesión del usuario | Cookie de sesión PHP + JWT firmado con `session.key`, con `jti` revocable |
| Datos de los clientes de cada empresa | Base de datos, aislados por `empresaSelected['id']` de la sesión |
| Archivos subidos | `public/` (sin ejecución de PHP) y carpetas de la aplicación |
| Archivos privados de la aplicación | `Storage`, en `versaWYS/storage`, que el servidor web no sirve |
| Trabajos pendientes | Tabla `versa_jobs`: clase y datos en JSON |

## Límites de confianza

```
navegador ──► proxy / CDN ──► servidor web ──► kernel ──► aplicación (app/) ──► BD, archivos, SMTP, procesos
 (no confiable)  (confiable solo si está en trusted_proxies)
```

Todo lo que llega en la petición es **no confiable**: parámetros, cuerpo, cookies, archivos y cabeceras. Eso incluye `Host` y `X-Forwarded-*`, salvo que vengan de una IP de `trusted_proxies`.

## Amenazas y mitigaciones

| Amenaza | Mitigación en el kernel | Prueba |
|---|---|---|
| CSRF | Token obligatorio en `POST/PUT/PATCH/DELETE`; `GET` y `QUERY` no cambian estado. Excepción explícita: `->withoutCsrf()`. Solo dos cadenas no vacías e iguales validan. | CsrfTest; HttpTest: «exige el token CSRF», «acepta el token por cabecera» |
| Robo o reutilización de sesión | Cookie `HttpOnly`/`Secure`/`SameSite`, modo estricto y id solo por cookie (aunque el php.ini diga otra cosa); id de sesión y CSRF se renuevan al iniciar sesión; JWT HS256 firmado con `session.key` y revocable por `jti` | SessionCookieTest; JwtTest (alterado, otra clave, `alg: none`, vencido, revocación); HttpTest: «el logout invalida el token» |
| Fuerza bruta en el login | Rate limiting por IP y por cuenta (correo normalizado) | RateLimitTest; HttpTest: «bloquea el login tras demasiados intentos» |
| IP o esquema falsificados (`X-Forwarded-*`) | Solo se creen desde `trusted_proxies`; las entradas inválidas se ignoran y `app:check:deploy` las marca | RequestProxyTest |
| Envenenamiento de enlaces de recuperación (`Host`) | `build.base_url` fija la URL pública; sin ella, `Host` solo se acepta si es un nombre de host o IP con puerto | RequestProxyTest: base URL, `X-Forwarded-Host`, formatos de `Host` |
| Inyección SQL en listados | `getParamsPaginate` devuelve marcadores y `bindings`; solo columnas de `allowedFilters`; `order` solo `columna [asc\|desc]` | KernelTest: «filtros de listados»; HttpTest: «no se ejecutan como SQL» |
| XSS | La entrada se guarda tal cual y se escapa al mostrar (Twig siempre, Vue por defecto). CSP con nonce y sin `eval` limita lo que un escape faltante permite ejecutar. | TwigTest; HttpTest: «entrada sin reescribir», «CSP con nonce», «unsafe_eval» |
| Subida de archivos ejecutables o fuera de su carpeta | Tipo detectado en el contenido; sin extensiones ejecutables, archivos ocultos ni `..` en el destino; `public/` sin PHP | FileTest; HttpTest: «rechaza un PHP disfrazado de imagen» |
| Filtración de detalles internos | En producción, los errores responden 500 sin SQL ni rutas, y un `message`/`errors` con rastros internos (p. ej. `$e->getMessage()` de VersaORM) se reemplaza por una referencia; `/up` da detalle solo con firma | ResponseTest; RouterTest: excepción no controlada; HttpTest: «un fallo de base de datos responde 500…»; HealthTest |
| Archivos internos servidos por la web | `.htaccess`/nginx del kernel; `app:check:deploy --url` los pide al sitio real | HttpTest: «app:check:deploy --url» |
| Inyección de comandos | `CommandExecutor` escapa el comando y cada parámetro con `escapeshellarg`; los nombres de generadores solo aceptan letras, números, `_`, `-` y `.` | KernelTest: «rechaza rutas en los nombres de generadores» |
| Correo interceptado | SMTP verifica el certificado salvo `mail.verify_peer: false` | — |
| Secretos en los logs | El contexto se limpia de claves con nombre de secreto (`password`, `token`, `api_key`…) a cualquier profundidad | LogTest |
| Rutas que se pisan | `route:check` y `app:check:deploy` marcan método + URL duplicados | RouteDuplicatesTest |
| *Method override* para saltarse CSRF | Solo un POST cambia de método, y los métodos que modifican siguen exigiendo CSRF | RouterTest: override |
| Leer o cambiar datos de otra empresa por id | `Tenant::ensure($fila)` responde 404, sin confirmar que existe; `Tenant::id()` sale de la sesión, no de la petición | AuthorizationTest; versaTodo: «otra empresa no ve ni modifica nada» |
| Acción sin permiso | `Gate::authorize` responde 403. Una habilidad no definida niega | AuthorizationTest |
| Datos sin validar en un controlador | Con una `FormRequest`, el método no corre si los datos fallan (422) | AuthorizationTest; ValidatorTest |
| Traversal en archivos de la aplicación | `Storage` rechaza `..`, rutas absolutas, unidades de Windows y bytes nulos | CacheStorageTest |
| Objetos inyectados por la caché o la cola | Caché y cola guardan JSON, nunca `serialize`: leerlas no crea objetos | CacheStorageTest; QueueTest |
| Código fuente expuesto en errores | La página con el código solo existe con `build.debug`; en producción, 500 sin detalles | DebugPageTest; ResponseTest |

## Fuera del alcance del kernel

El kernel no puede garantizar esto; queda en cada proyecto:

- SQL armado a mano en `app/` (`whereRaw`, `selectRaw` o `sprintf` con datos de la petición).
- `|raw` y `v-html` con datos que escribe un usuario.
- Usar `Tenant`, `Gate` o un filtro por empresa en cada consulta. El kernel da las herramientas, pero no las aplica solo.
- Secretos escritos en el código o en seeders. `app:check:deploy` los busca, pero no puede encontrarlos todos.

## Límites conocidos

- **Un solo servidor.** Rate limiting, revocación de JWT, caché en archivos y `Storage` son locales. Con varios nodos, cada uno cuenta, revoca y guarda por separado; hace falta almacenamiento compartido (paso 5 del ROADMAP). La cola funciona con varios, porque vive en la base de datos.
- **Fallo abierto del rate limiting** si su carpeta no es escribible. Ver [SECURITY.md](SECURITY.md#comportamiento-ante-fallos).
- **`'unsafe-inline'`** sigue en `style-src` (Tailwind y Vue) y en `script-src` solo si `csp.nonce` está apagado.
