# Rendimiento: línea base

Antes de optimizar algo, medirlo contra esta base y publicar la comparación en el CHANGELOG. Un cambio que no mejora la mediana no se justifica por rendimiento.

## Kernel en memoria

```bash
composer bench          # php bench/run.php con OPcache y sin Xdebug
```

Mide en el mismo proceso, sin servidor ni base de datos, con 60 rutas registradas (la mitad con parámetros). Imprime el entorno junto a los resultados.

**2026-10-14, kernel 3.2.0** · PHP 8.4.18 (cli) · OPcache sí · Xdebug no · Intel Core i9-13900H · 3000 iteraciones · dos corridas:

| Escenario | p50 (µs) | p95 (µs) |
|---|---|---|
| Registrar 60 rutas | 16.2 – 16.9 | 17.0 – 24.8 |
| Resolver ruta exacta | 10.5 – 10.8 | 11.3 – 14.7 |
| Resolver ruta con parámetro | 29.1 – 29.3 | 30.7 – 31.6 |
| Crear `Request` | 0.7 | 0.8 |

**2026-10-19, kernel 3.11.1** (mismo equipo y PHP, 2000 iteraciones):

| Escenario | p50 (µs) | p95 (µs) |
|---|---|---|
| Registrar 60 rutas | 16.1 | 16.7 |
| Resolver ruta exacta | 13.3 | 13.9 |
| Resolver ruta con parámetro | 40.9 | 45.5 |
| Crear `Request` | 0.7 | 0.8 |

Lo que subió desde la 3.2.0 se explica por funciones agregadas, medidas por separado: comprobar si la
instalación está pendiente (2,0 µs) y el modo mantenimiento (2,3 µs) leen un archivo cada uno; el profiler
apagado cuesta 0,4 µs; y la ruta con parámetro pasa por el contenedor (3.6.0), que revisa por reflexión si
el controlador pide servicios. En total, menos del 1 % de una petición real (1,4 ms): no se optimiza.

La mediana se repite entre corridas; el p95 depende de la carga del equipo. Comparar solo con el mismo equipo y la misma configuración de PHP.

## Aplicación completa (esqueleto)

```bash
vendor/bin/pest --group=bench --configuration versaWYS/phpunit.xml   # en el esqueleto
```

Levanta `php -S` con SQLite y mide por HTTP. **2026-10-14**, OPcache, sin Xdebug, caché de Twig activa, p50:

| Escenario | p50 |
|---|---|
| Página Twig | 1,4 ms |
| API con sesión | 1,9 ms |
| 404 | 1,1 ms |

Con Xdebug activo y la caché de Twig apagada, la misma página tardaba 15 ms. Lo que más pesa en producción es la configuración de PHP, no el kernel: `app:check:deploy --production` revisa OPcache y Xdebug en el servidor web a través de `/up`.

## Lo que ya se descartó

- **Caché de rutas compilada.** Cargar `app/routes` tardaba 308 µs y la caché concatenada 463 µs. Se eliminó en 2.10.
- **Caché de `config.json` en PHP.** Leer y fusionar la configuración cuesta 21 µs por petición: no vale una capa de invalidación.
