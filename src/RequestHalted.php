<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Error;

/**
 * En pruebas reemplaza al exit() que termina una petición (ver Output::halt). No es un error: el cliente de
 * pruebas la atrapa y la respuesta ya registrada es la de la petición.
 *
 * Extiende Error y no Exception para que un catch (Exception) de la aplicación no la trague, como tampoco
 * atraparía un exit(). Los catch (Throwable) del kernel la dejan pasar.
 */
final class RequestHalted extends Error {}
