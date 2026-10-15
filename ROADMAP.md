# Hoja de ruta: trabajo pendiente en versawys-kernel

## Objetivo

Evolucionar el kernel para que permita construir aplicaciones robustas y profesionales, con seguridad, herramientas y calidad comparables a las expectativas de frameworks PHP maduros, sin intentar replicar toda la amplitud de Laravel o Symfony ni perder ligereza.

Este documento cubre **solo `versawys-kernel`**. El esqueleto `versaWYS-PHP` contiene la aplicación base, vistas y otras capacidades de producto; sus cambios no forman parte de esta hoja de ruta. Los contratos de integración con ese repositorio sí deben probarse cuando un cambio del kernel pueda afectarlos.

## Situación actual

El kernel ya tiene Router y middlewares, Request/Response, sesiones y JWT, CSRF, rate limiting, Twig, CLI, logging e integración con VersaORM. También cuenta con CSP y cabeceras de seguridad, soporte de proxies confiables, PHPStan, Pint y una CI que prueba el esqueleto contra el kernel.

Como primera base ya se incorporaron:

- PHPUnit y una suite inicial para Request, Router y resolución de proxies confiables.
- Ejecución de las pruebas desde `composer check`.
- Rechazo y registro de rangos CIDR inválidos en `trusted_proxies`, también detectados por `app:check:deploy` (3.1.2).
- Valor GET por defecto si no se define `REQUEST_METHOD`.
- Pruebas del Router: `code` de la respuesta como estado HTTP, middlewares que cortan la petición, parámetros de ruta; y de `/up`.
- `/up` entrega el detalle del entorno solo con una cabecera firmada con `session.key` (3.1.2).
- `THREAT_MODEL.md` y la sección «Comportamiento ante fallos» de `SECURITY.md` (paso 2.1 y parte del 2.3).

Pruebas que ya existen en el esqueleto y cubren al kernel por HTTP (las corre la CI del kernel): CSRF, sesión y revocación de JWT, rate limiting del login, 404/405, `QUERY`, CSP con nonce y sin `eval`, entrada sin reescribir, filtros de listados, archivos subidos, `app:check:deploy --url` y `config:merge`. El benchmark está en `pest --group=bench` del esqueleto. Las pruebas nuevas que no necesitan un servidor van en este repositorio.

Esto inicia la base de pruebas, pero **no completa** el inventario de contratos, la cobertura del kernel ni la revisión de seguridad. El orden siguiente es recomendado; cada etapa debe acabar con sus pruebas y criterios de aceptación antes de ampliar la siguiente.

## Principios para todas las etapas

- Mantener pocos requisitos obligatorios y justificar cualquier nueva dependencia de producción.
- Preferir capacidades optativas —por ejemplo, Redis— frente a exigir infraestructura adicional a todos los proyectos.
- No reescribir ni escapar genéricamente los datos de entrada; validar según el uso y escapar al presentar.
- Preservar las APIs existentes de forma gradual: añadir, documentar y deprecar antes de retirar o cambiar comportamiento.
- Medir rendimiento antes de optimizar y publicar las mediciones con el entorno y los escenarios usados.
- Actualizar `CHANGELOG.md`, `UPGRADE.md` y `SECURITY.md` cuando el cambio lo requiera.

## Paso 1 — Completar contratos, pruebas y línea base

**Estado: completo (3.2.0).**
1. Inventario en `API.md`: estable / interno / deprecado, con reglas de compatibilidad.
2. Contrato kernel ↔ app probado (`AppContractTest`): el kernel solo importa clases del contrato y UPGRADE.md lo lista exacto.
3. Suite PHPUnit con 65 pruebas: Router, Request, archivos, rate limiting, validación, configuración, logs, CLI y comandos. CSRF, sesión y JWT siguen cubiertos por HTTP en el esqueleto.
4. Regresiones: CSP/nonce (esqueleto), proxies, entrada sin reescribir, rate limiting con concurrencia y estado HTTP desde `code`.
5. Línea base en `BENCHMARK.md` (`composer bench`), con entorno y comandos.

### Trabajo pendiente

1. Inventariar las APIs públicas, configuración, comandos CLI y dependencias de producción. Clasificar cada elemento como contrato estable, interno o candidato a deprecación.
2. Enumerar y probar los contratos kernel ↔ esqueleto, incluidos los métodos/clases de aplicación que consume el kernel. Señalar explícitamente los cambios que requieren versión mayor.
3. Ampliar la suite PHPUnit con pruebas de:
   - Router: grupos, parámetros, métodos, HEAD/OPTIONS, 404/405 y middleware.
   - Request/Response: cabeceras, cuerpo JSON/formularios, estados HTTP, errores y comportamiento SPA/API.
   - CSRF, cookies, sesión/JWT, expiración y revocación.
   - Archivos: errores de carga, validación del tipo real, nombres y rutas de destino.
   - Configuración, logging, errores de producción y comandos CLI críticos.
4. Añadir pruebas de regresión para CSP/nonce, proxy confiable, entrada sin reescritura, rate limiting y comportamiento de actualización del esqueleto.
5. Establecer una línea base repetible para tiempo de arranque, memoria, cantidad/tamaño de dependencias y rendimiento de rutas. Registrar versiones de PHP, extensiones, OPcache, hardware/runner y comandos utilizados.

### Criterio de salida

- Pruebas propias del kernel corren localmente y en CI.
- Cambios en rutas críticas y contratos de aplicación tienen pruebas de regresión.
- Hay métricas repetibles con las que comparar futuras optimizaciones.

## Paso 2 — Reforzar seguridad y fiabilidad

**Estado: completo (3.1.2 y 3.2.0).**
1. `THREAT_MODEL.md`.
2. Pruebas de abuso: override, panel oculto, barras, `Host` y `X-Forwarded-*`, traversal y extensiones en subidas, 404/405 sin detalles, escape de comandos. Encontraron y corrigieron: traversal en `moveToNewName`, `Host` sin validar, reemplazo de la URL del panel.
3. Fallos de almacenamiento: `SECURITY.md`, log `security` y `app:check:deploy`.
4. `app:check:deploy` valida tipos, claves desconocidas en secciones de seguridad, claves sin efecto y `trusted_proxies`.
5. Valores por defecto probados (esqueleto: «nace con valores seguros»).
6. PHP 8.3–8.5 en la CI, `composer audit`, Dependabot y proceso de divulgación en `SECURITY.md`.

### Trabajo pendiente

1. Crear un modelo de amenazas conciso: datos no confiables, límites entre cliente/proxy/kernel, secretos, sesiones, archivos, rutas, correo, comandos y despliegue.
2. Revisar con pruebas de abuso y casos límite:
   - Normalización y matching de rutas, parámetros y method override.
   - CSRF, cookies, sesiones, JWT, fijación de sesión, revocación y expiración.
   - `X-Forwarded-*`, validación CIDR, `Host` y construcción de URLs.
   - Carga de archivos, MIME real, traversal, sobrescritura de archivos y permisos.
   - Errores en producción, respuestas JSON/HTML y ausencia de detalles internos.
   - Invocación de comandos, correo y cualquier salida a procesos externos.
3. Revisar bloqueos, concurrencia, permisos, caducidad y fallos de almacenamiento en rate limiting y revocación JWT. Definir el resultado operativo cuando el backend no esté disponible; no continuar silenciosamente si eso desactiva una protección que la configuración exige.
4. Implementar un comando/servicio de validación de configuración para detectar tipos inválidos, claves desconocidas relevantes, secretos faltantes o débiles en producción, combinaciones inseguras y directorios no escribibles.
5. Revisar defaults de seguridad y comprobarlos con pruebas, manteniendo la configuración de desarrollo claramente separada de producción.
6. Mantener auditoría de dependencias, matriz de PHP soportado y controles de seguridad en CI; definir el proceso de respuesta/divulgación en `SECURITY.md`.

### Criterio de salida

- Las superficies de ataque y límites de confianza están documentados.
- Las opciones de seguridad críticas tienen pruebas, incluyendo condiciones de error.
- Los fallos de almacenamiento o configuración no producen una respuesta que aparente protección si esta no está activa.

## Paso 3 — Ordenar fundamentos e interoperabilidad

**Estado: hecho lo que tiene consumidores (3.2.0); el resto, decidido en `DECISIONS.md`.**
1. `LIFECYCLE.md`.
2. Rutas duplicadas detectadas en `route:check` y `app:check:deploy`; los callbacks inexistentes ya se detectaban.
3–5. Contenedor de dependencias, PSR-3/11/14 y Request sin superglobales: evaluados y no adoptados, con la señal que haría revisarlos.

### Trabajo pendiente

1. Documentar y formalizar el ciclo de vida de una petición: construcción de Request, middleware, resolución, controlador, Response y tratamiento de errores.
2. Fortalecer Router: tipos y validación de callbacks, detección de rutas duplicadas/ambiguas, reglas de parámetros y respuestas HTTP consistentes. Mantener una fachada compatible para las rutas ya registradas por aplicaciones.
3. Diseñar inyección de dependencias para controladores y middleware. Evaluar primero la necesidad real de un contenedor PSR-11 o una implementación propia mínima; el uso de DI no debe ser obligatorio para proyectos sencillos.
4. Reducir gradualmente dependencias directas de superglobales y salida directa en Request/Response, sin romper la API actual. Habilitar pruebas en memoria y dejar abierta una integración futura con otros entornos de ejecución.
5. Evaluar PSR-3 para logging, PSR-11 para contenedor y PSR-14 para eventos. Adoptar solo los contratos que mejoren interoperabilidad y eviten acoplamiento; no crear abstracciones sin consumidores.

### Criterio de salida

- El flujo HTTP y sus contratos están documentados y probados.
- Los cambios nuevos pueden probarse sin levantar un servidor real cuando no sea necesario.
- Las aplicaciones existentes pueden seguir usando la API actual durante la transición.

## Paso 4 — Completar herramientas de desarrollo y servicios

**Estado: completo para los casos de uso actuales (3.2.0).**
1. CLI: `--no-interaction`; una operación destructiva cancelada sale con código 1.
2. Validación: `nullable`, `in`, `boolean`; longitudes en caracteres. Mensajes traducibles: ver `DECISIONS.md`.
3. Utilidades HTTP: sin capa nueva; `LIFECYCLE.md` documenta las existentes.
4. Migraciones en transacción, una por migración.
5. Eventos: ver `DECISIONS.md`.

### Trabajo pendiente

1. Hacer más consistente el CLI: generación de componentes, argumentos/opciones validados, códigos de salida estables y ejecución no interactiva para automatización.
2. Proveer validación de entrada reutilizable con reglas tipadas, mensajes traducibles y salida de errores uniforme, conservando los valores originales recibidos.
3. Revisar las utilidades HTTP más usadas: respuestas, redirecciones, negociación de contenido, cabeceras/cookies y límites/validación de subidas. Evitar añadir una capa duplicada si ya existe en dependencias.
4. Fortalecer migraciones VersaORM con transacciones cuando el motor las soporte, errores consistentes, protección frente a operaciones destructivas y mejor diagnóstico de estado. Coordinar responsabilidades con el ORM en vez de duplicarlas.
5. Añadir eventos/hooks del ciclo de vida únicamente si resuelven extensibilidad concreta y no añaden coste relevante cuando no hay listeners.

### Criterio de salida

- Las herramientas principales se usan de forma automatizada y reportan fallos con códigos consistentes.
- Validación y errores tienen APIs documentadas, probadas y compatibles con los proyectos actuales.
- Las responsabilidades del kernel y VersaORM quedan claramente delimitadas.

## Paso 5 — Mejorar operación, escalado y rendimiento

**Estado: hecho lo que aplica a un servidor (3.1.2 y 3.2.0); varios nodos, decidido en `DECISIONS.md`.**
1–2. Almacenamiento compartido (Redis): no hay proyectos con más de un servidor; límite documentado y diseño previsto en `DECISIONS.md`.
3. Observabilidad: `request_id` en logs y en `X-Request-Id`, secretos ocultos, IP real del cliente.
4. `/up` solo da detalle con firma.
5. Perfilado: `BENCHMARK.md`; las cachés de rutas y configuración se descartaron con mediciones.

### Trabajo pendiente

1. Diseñar interfaces acotadas para almacenamiento de rate limits y revocación JWT; mantener archivos como opción sencilla de un solo servidor y agregar Redis como adaptador optativo para despliegues distribuidos.
2. Documentar y probar las limitaciones de sesiones, rate limiting y revocación en múltiples nodos. Indicar qué componentes requieren almacenamiento compartido y cómo configurarlo.
3. Mejorar observabilidad con identificador de correlación por petición, redacción de secretos/datos sensibles, niveles de log uniformes y métricas operativas básicas.
4. Revisar que health checks expongan solo información apropiada para el origen que consulta; no revelar detalles de entorno a clientes remotos.
5. Perfilar arranque, carga de rutas, plantillas, filesystem y logging. Aplicar optimizaciones solo si mejoran las métricas de referencia sin sacrificar invalidez de caché, frescura de rutas o simplicidad.

### Criterio de salida

- Existe un camino documentado desde instalación de un solo nodo hasta almacenamiento distribuido, sin exigir Redis a todos.
- Logs y diagnósticos son útiles para operar y no revelan secretos.
- Toda optimización reporta su comparación con la línea base y conserva pruebas de comportamiento.

## Paso 6 — Documentar compatibilidad y publicación

**Estado: al día en 3.2.0; continuo en cada versión.**
1. Documentación del kernel: `README.md` (índice), `API.md`, `LIFECYCLE.md`, `SECURITY.md`, `THREAT_MODEL.md`, `BENCHMARK.md`, `DECISIONS.md`; la del uso del framework vive en `documentacion/` del esqueleto.
2. Versiones de PHP, SemVer y deprecación en `README.md`.
3. `UPGRADE.md` por versión; `app:check:deploy` detecta contratos rotos.
4. Cada entrega con CHANGELOG, pruebas, auditoría, phpstan y la CI del esqueleto.

### Trabajo pendiente

1. Documentar configuración, ciclo de vida, rutas, middleware, respuestas/errores, seguridad, CLI, pruebas, despliegue, escalado y límites operativos, con ejemplos ejecutables.
2. Definir y documentar versiones de PHP soportadas, proceso de actualización de dependencias, política SemVer y ciclo de deprecación.
3. Actualizar `UPGRADE.md` por cada cambio incompatible o de configuración y añadir comprobaciones que detecten contratos rotos con el esqueleto.
4. Publicar cada mejora en entregas pequeñas: changelog, pruebas, auditoría de dependencias, análisis estático y CI del kernel más integración con el esqueleto.
5. Antes de considerar cumplida la meta, revisar el conjunto de criterios de salida de todos los pasos y publicar límites conocidos; no declarar equivalencia total con Laravel/Symfony por número de funciones.

### Criterio de salida

- Una aplicación puede instalarse, configurarse, probarse, desplegarse y actualizarse siguiendo documentación vigente.
- La matriz CI soportada pasa; auditoría de dependencias y pruebas de integración están verdes.
- Hay instrucciones de migración y no quedan cambios de API incompatibles sin documentar.

## Paso 7 — Al nivel de Laravel y Symfony en el trabajo diario

**Estado: fases A–E completas (3.5.0 a 3.9.0).**

El objetivo de los pasos 1–6 era un kernel robusto sin replicar la amplitud de Laravel o Symfony. Este paso apunta más alto: igualarlos en lo que hace seguro y productivo el trabajo diario. No se mide por número de funciones. Cada fase mantiene la API actual y los proyectos adoptan lo nuevo de a poco.

| Fase | Qué | Estado |
|---|---|---|
| A | Probar una aplicación sin servidor: `Testing\TestClient`, salida por `Output`, estado por petición reiniciable | Hecha (3.5.0) |
| B | Contenedor optativo con inyección por constructor en controladores y middlewares; `global` sigue funcionando | Hecha (3.6.0) |
| C | Validación con reglas en arreglo, datos anidados y Form Requests; autorización con políticas y aislamiento por empresa | Hecha (3.7.0) |
| D | Colas, caché y almacenamiento de archivos con adaptadores simples (archivos/BD primero, Redis/S3 optativos) | Hecha (3.8.0): Redis para la caché; S3 cuando un proyecto lo pida |
| E | Página de error con contexto, sitio de documentación, toolchain de frontend sin trampas (builds mezclados, importmap) | Hecha (3.9.0): página de error con código; builds mezclados resueltos en versaCompiler. El sitio de documentación espera a tener usuarios externos (GitHub ya muestra `documentacion/`) |

Por qué A primero: los errores que encontró la app de prueba (versaTodo) aparecieron a mano o con un navegador sin interfaz. Con un cliente de pruebas, las suites de cada proyecto los detectan. La primera suite con `TestClient` encontró un 500 en lugar de 422 en el panel base (`EmpresasMiddleware`).

## Dependencias y orden sugerido

1. Completar el **paso 1** antes de hacer refactors de arquitectura o medir optimizaciones.
2. Ejecutar el **paso 2** en paralelo con la ampliación de pruebas, y cerrar riesgos antes de introducir nuevas capacidades de producción.
3. Desarrollar el **paso 3** con contratos protegidos y cambios graduales.
4. Implementar el **paso 4** según casos de uso demostrados.
5. Abordar el **paso 5** sobre APIs y métricas estables.
6. Completar documentación y compatibilidad del **paso 6** en cada entrega; cerrar la etapa cuando se satisfagan todos los criterios.

La meta no es implementar cada idea de forma automática: antes de cada elemento se debe confirmar que aporta valor a usuarios del kernel, que no pertenece mejor al esqueleto o a VersaORM y que su costo no compromete la ligereza.
