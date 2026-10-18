<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use RuntimeException;

/**
 * El recurso no existe (o es de otra empresa): el Router responde 404, una página o JSON según la
 * petición. Desde un controlador o un middleware:
 *
 *     $factura = $this->facturas->buscar($id) ?? throw new NotFound();
 */
class NotFound extends RuntimeException {}
