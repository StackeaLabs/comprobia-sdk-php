<?php

declare(strict_types=1);

namespace Comprobia;

/** El transporte por defecto, con ext-curl. Sin dependencias. */
final class TransporteCurl implements Transporte
{
    public function __construct(
        private readonly int $tiempoMaximo = 60,
        private readonly int $tiempoConexion = 10,
    ) {}

    public function enviar(string $metodo, string $url, array $cabeceras, ?string $cuerpo): Respuesta
    {
        $curl = curl_init($url);
        $recibidas = [];

        $lineas = [];
        foreach ($cabeceras as $nombre => $valor) {
            $lineas[] = "{$nombre}: {$valor}";
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $lineas,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->tiempoMaximo,
            CURLOPT_CONNECTTIMEOUT => $this->tiempoConexion,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($_, string $linea) use (&$recibidas): int {
                $partes = explode(':', $linea, 2);

                if (count($partes) === 2) {
                    $recibidas[strtolower(trim($partes[0]))] = trim($partes[1]);
                }

                return strlen($linea);
            },
        ]);

        if ($cuerpo !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $cuerpo);
        }

        $contenido = curl_exec($curl);

        if ($contenido === false) {
            // Sólo el número del error: el mensaje de cURL puede repetir la URL.
            $numero = curl_errno($curl);
            curl_close($curl);

            throw new ErrorDeConexion("No hubo respuesta de Comprobia (cURL {$numero}).");
        }

        $estado = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return new Respuesta($estado, $recibidas, (string) $contenido);
    }
}
