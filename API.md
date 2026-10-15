# API pública del kernel

Qué puede usar una aplicación y qué puede cambiar sin aviso. Inventario del 2026-10-14 (kernel 3.1.2), hecho buscando cada método en el kernel, el esqueleto y los cuatro proyectos en producción (vservDigitalizacion, versaSTORE, anima, versaBOT).

## Reglas de compatibilidad

- **Estable**: lo usa al menos una aplicación. Quitarlo o cambiar su firma es una **versión mayor**, y antes pasa al menos una versión menor marcado `@deprecated`, con su reemplazo en UPGRADE.md.
- **Interno**: solo lo llama el kernel (por ejemplo, el CLI). Puede cambiar en cualquier versión; una aplicación no debe usarlo.
- **Deprecado**: sigue funcionando y se elimina en la siguiente versión mayor. `@deprecated` en el código dice qué usar en su lugar.
- **Configuración**: agregar una clave con valor por defecto es una versión menor. Cambiar el significado o el valor por defecto de una existente es mayor, salvo que el cambio solo cierre un hueco de seguridad (se anuncia en CHANGELOG y SECURITY.md).
- **Contrato kernel ↔ app**: los métodos de `app/` que el kernel llama están en `CheckDeployCommand::APP_CONTRACT` (ver UPGRADE.md). Ampliar esa lista es una versión mayor.
- **Comandos CLI**: los listados en `LineaDeComandos.md` del esqueleto son estables (nombre, opciones y código de salida 0/1).

## Deprecado (se elimina en 4.0)

| Método | Reemplazo |
|---|---|
| `SecurityUtils::validateCsrfToken()` | `Functions::validateCSRFToken()`, que es la que usa el Router |
| Configuración `session.user_cookie.key_encript`, `twig.default_template_404`, `twig.auto_escape`, `api.auth`, `rate_limiting.storage`, `build.cacheRoute*` | Ninguno: no tienen efecto. `app:check:deploy` avisa si siguen en `config.json` (lista en `CheckDeployCommand::DEAD_KEYS`) |

## Configuración

Claves de primer nivel de `config.default.php`, todas estables: `framework`, `database`, `admin`, `twig`, `build`, `trusted_proxies`, `auth`, `session`, `api`, `login_attempt`, `mail`, `cors`, `csp`, `websocket_service`, `logging`, `redis`, `rate_limiting`, `assets`.

## Clases

### Estables (las usan las aplicaciones)

En cada clase, los métodos marcados *interno* solo los llama el kernel y pueden cambiar sin aviso.

- **`App`** — `run`
- **`CacheUsers`** — `updateMenuVersion`, `invalidateUserCache`, `invalidateAllUserCache`; *interno*: `getUserData`
- **`Cookie`** — `set`, `has`, `get`, `delete`, `getAll`, `clear`
- **`ErrorHandler`** — `initialize`, `handleError`; *interno*: `handleException`, `handleShutdown`
- **`File`** — `offsetExists`, `offsetGet`, `offsetSet`, `offsetUnset`, `getName`, `getExtension`, `getSize`, `getSizeForHumans`, `getType`, `getTmpName`, `getMetadata`, `moveToOriginalName`, `moveToNewName`; *interno*: `isValidUpload`
- **`GlobalControllers`** — `getParamsPaginate`
- **`GlobalMiddleware`** — `getLoadedUserData`, `checkSession`, `validateCSRFToken`, `onlyAdmin`, `onlySuperAdmin`, `validatePolicyPassword`, `getMessagePolicyPassword`, `onlyDebug`, `checkLoginRateLimit`, `onlyUserWithAccess` (lo escribe route:make), `panelPath`; *interno*: `isPasswordExpired`, `resetRequestState`
- **`Loggin`** — `initialize`, `setCustomHandler`, `log`, `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`, `exception`, `query`, `request`, `auth`, `performance`, `read`, `getConfig`
- **`MailSender`** — `send`
- **`Output`** — `header`, `removeHeader`, `status`, `cookie`, `halt`, `capturing`; *interno*: `capture`, `release`. El kernel escribe la respuesta solo por aquí: en producción equivale a `header()`, `http_response_code()`, `setcookie()` y `exit()`
- **`RequestHalted`** — en pruebas reemplaza al `exit()` de una petición (extiende `Error`)
- **`RateLimitMiddleware`** — `checkApiLimit`, `checkLoginLimit`, `checkUploadLimit`, `checkSensitiveLimit`
- **`Request`** — `setParam`, `get`, `file`, `getAllParams`, `getRawParams`, `getAllFiles`, `getContentType`, `getMethod`, `getUrl`, `getFiles`, `getIp`, `getClientIp`, `getRawBody`, `getBaseUrl`, `clientIp`, `isTrustedProxy`, `getHeader`, `isApiCall`, `id`; *interno*: `setInput`, `resetRequestState`, `procesaFormData`, `parseProxyRange`, `isSPARequest`, `isSPARequestStatic`, `isApiRequest`
- **`Response`** — `json`, `jsonError`, `textPlain`, `redirect`; *interno*: `resetRequestState`
- **`Router`** — `health`, `normalize_url`, `group`, `get`, `post`, `put`, `patch`, `delete`, `query`, `withoutCsrf`, `middleware`, `resolve`; *interno*: `loadRoutes`, `healthToken`
- **`SecurityUtils`** — `validateAndCleanUrl`; *interno*: `sanitizeUrl`, `cspNonce`, `resetRequestState`, `checkRateLimit`, `checkGlobalRateLimit`, `cleanExpiredLimits`; *deprecado*: `validateCsrfToken`
- **`Session`** — `revokeToken`, `regenerate`, `setCSRFToken`, `getCSRFToken`, `set`, `has`, `get`, `getAll`, `delete`, `clear`, `getAttempts`, `getAllAttempts`, `setAttempts`, `restoreAttempts`, `setAttemptsSession`, `maximumAttempts`, `generateJWT`, `decodeJWT`, `setUserSession`, `refreshUserSession`, `checkUserSession`, `touchSessionActivity`, `destroy`; *interno*: `checkExistSession`, `checkUserSessionCached`, `resetRequestState`
- **`versaController`** — `versaRoute`
- **`versaTwig`** — `init`, `addGlobal`, `render`, `errorHandler`; *interno*: `addExtension`, `addFilter`, `renderDirect`
- **`cli\BaseCommand`** — —; *interno*: `getHelp`
- **`cli\CLIErrorHandler`** — `success`, `info`, `warning`, `error`, `colorize`; *interno*: `handleDatabaseError`, `handleMigrationResultError`, `handleMigrationError`, `handleGeneralError`, `printSeparator`
- **`cli\CLIHelper`** — `ask`, `confirm`; *interno*: `composerDir`, `ensureDirectory`
- **`cli\CommandExecutor`** — `runInBackground`, `run`
- **`cli\CommandInterface`** — `execute`; *interno*: `getDescription`, `getHelp`
- **`cli\ConfigManager`** — `dir`, `path`, `defaults`, `getConfig`, `saveConfig`, `setDebug`, `merge`, `align`, `clearCache`; *interno*: `setTemplateCache`, `mergeArrays`, `initConfig`
- **`helpers\FechasFunction`** — `formatFechaLargaES`; *interno*: `formaFechaFullES`, `formatFecha`, `setTwigFilters`, `setTwigFunctions`
- **`helpers\Functions`** — `dump`, `cleanString`, `validateParams`, `e`, `validarRut`, `hash`, `needsRehash`, `verifyHash`, `generateCSRFToken`, `validateCSRFToken`, `assets_url`, `csrf_field`, `getAssets`, `removeScape`, `sanitizeSvgContent`, `formatRut`, `getPanelUrl`, `httpRequest`, `httpGet`, `httpPostJson`, `httpUploadFile`; *interno*: `validateJson`, `replace_spaces_with_underscore`, `method_field`, `setTwigFilters`, `cspNonce`, `setTwigFunctions`
- **`Testing\TestClient`** — `boot`, `withHeaders`, `withoutCsrfToken`, `login`, `get`, `getJson`, `post`, `postJson`, `putJson`, `patchJson`, `deleteJson`, `json`, `request`, `cookies`
- **`Testing\TestResponse`** — `header`, `json`, `assertStatus`, `assertOk`, `assertRedirect`, `assertHeader`, `assertSee`, `assertDontSee`, `assertJson`, `assertJsonPath`
- **`model\OrmFactory`** — `make`
- **`model\RedisModel`** — `initialize`, `getInstance`, `set`, `get`, `delete`, `del`, `exists`, `expire`, `ttl`, `increment`, `decrement`, `rPush`, `lPop`, `lRange`, `lLen`, `scan`, `searchValueBySubKey`, `info`, `ping`, `close`, `multi`, `exec`, `remember`, `forget`, `pull`, `add`, `replace`; *interno*: `setMultiple`, `getMultiple`, `hSet`, `hGet`, `hSetMultiple`, `hGetAll`, `hDel`, `hExists`, `lPush`, `rPop`, `sAdd`, `sMembers`, `sIsMember`, `sRem`, `sCard`, `zAdd`, `zRange`, `zRem`, `zScore`, `dbSize`, `flushDb`, `flushAll`, `rawCommand`, `discard`
- **`model\VersaORMModel`** — `replaceOrm`, `orm`, `exec`, `tableName`, `definePropertyTypes`, `executeWithErrorHandling`, `escape`; *interno*: `boot`, `isBooted`, `setOrmInstance`

### Internas

La maquinaria del CLI y sus generadores. Se usan a través de `php versaCLI`, no desde el código de la aplicación.

`cli\ApplicationCommandManager`, `cli\CacheManager`, `cli\CommandLineInterface`, `cli\CommandMakeCommand`, `cli\CommandRegistry`, `cli\ControllerManager`, `cli\CronManager`, `cli\FrontManager`, `cli\HelpView`, `cli\LogManager`, `cli\MiddlewareManager`, `cli\MigrationManager`, `cli\ModelManager`, `cli\RouteManager`, `cli\SeederManager`, `cli\ServerManager`, `cli\StatusManager`, `cli\VersionManager`, `cli\commands\CheckDeployCommand`, `cli\commands\CheckPsr4Command`, `cli\commands\DeployFinishCommand`, `cli\commands\KernelPublishCommand`, `cli\commands\MailSendCommand`, `helpers\FilesFunction`.

## Cómo se mantiene

Al agregar un método público, decidir si es estable o interno. En una clase estable, un método interno lleva `@internal` en su docblock. Antes de una versión mayor, repetir el inventario: buscar cada método público en los proyectos conocidos (`app/`, `src/`, pruebas, `index.php`, `versaCLI`) y en el kernel.
