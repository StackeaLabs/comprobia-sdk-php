<?php

declare(strict_types=1);

namespace Comprobia;

use RuntimeException;

/**
 * La API contestó con un error (4xx o 5xx), en su formato único
 * `{ code, message, request_id, errors[] }`.
 *
 * Decide por `$codigo` (estable: `VALIDATION_ERROR`, `RATE_LIMITED`,
 * `YA_ACEPTADO`…), no por el mensaje. Si escribes a soporte, cita `$requestId`.
 */
final class ErrorDeComprobia extends RuntimeException
{
    /** @param  list<array<string, mixed>>  $errores  detalle por campo o regla: field, rule, message, sunat_code */
    public function __construct(
        public readonly int $estado,
        public readonly string $codigo,
        string $mensaje,
        public readonly ?string $requestId = null,
        public readonly array $errores = [],
    ) {
        parent::__construct($mensaje, $estado);
    }

    public static function desde(Respuesta $respuesta): self
    {
        $datos = $respuesta->json();
        $errores = isset($datos['errors']) && is_array($datos['errors']) ? array_values($datos['errors']) : [];

        return new self(
            $respuesta->estado,
            is_string($datos['code'] ?? null) ? $datos['code'] : 'HTTP_'.$respuesta->estado,
            is_string($datos['message'] ?? null) ? $datos['message'] : "La API respondió {$respuesta->estado}.",
            is_string($datos['request_id'] ?? null) ? $datos['request_id'] : $respuesta->cabecera('X-Request-Id'),
            $errores,
        );
    }
}
