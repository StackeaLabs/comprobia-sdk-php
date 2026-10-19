<?php

declare(strict_types=1);

namespace Comprobia;

/**
 * Quien hace la petición HTTP. El de verdad es TransporteCurl; los tests usan
 * uno de mentira, y quien ya tenga un cliente HTTP (Guzzle, el de su framework)
 * puede escribir el suyo en diez líneas.
 */
interface Transporte
{
    /**
     * @param  array<string, string>  $cabeceras
     *
     * @throws ErrorDeConexion si no hubo respuesta (sin red, DNS, tiempo agotado)
     */
    public function enviar(string $metodo, string $url, array $cabeceras, ?string $cuerpo): Respuesta;
}
