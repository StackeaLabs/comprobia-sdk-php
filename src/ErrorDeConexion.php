<?php

declare(strict_types=1);

namespace Comprobia;

use RuntimeException;

/**
 * No hubo respuesta: sin red, DNS, TLS o tiempo agotado. Si pasó al emitir,
 * reintenta con la MISMA clave de idempotencia (`$e->claveIdempotencia`): si la
 * primera llegó, la API devuelve el mismo comprobante y no emite otro.
 */
final class ErrorDeConexion extends RuntimeException
{
    public ?string $claveIdempotencia = null;
}
