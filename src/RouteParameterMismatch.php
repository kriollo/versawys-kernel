<?php

declare(strict_types=1);

namespace versaWYS\kernel;

/** Un parámetro de la ruta no calza con el tipo del método (p. ej. `int $id` con `/tareas/abc`): el Router responde 404. */
final class RouteParameterMismatch extends NotFound {}
