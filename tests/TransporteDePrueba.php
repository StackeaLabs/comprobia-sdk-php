<?php

declare(strict_types=1);

namespace Comprobia\Tests;

use Comprobia\ErrorDeConexion;
use Comprobia\Respuesta;
use Comprobia\Transporte;

/** Contesta lo que se le encola y anota cada petición. `null` en la cola = corte de red. */
final class TransporteDePrueba implements Transporte
{
    /** @var list<array{metodo: string, url: string, cabeceras: array<string, string>, cuerpo: ?string}> */
    public array $peticiones = [];

    /** @param  list<Respuesta|null>  $respuestas */
    public function __construct(private array $respuestas) {}

    public function enviar(string $metodo, string $url, array $cabeceras, ?string $cuerpo): Respuesta
    {
        $this->peticiones[] = ['metodo' => $metodo, 'url' => $url, 'cabeceras' => $cabeceras, 'cuerpo' => $cuerpo];
        $siguiente = array_shift($this->respuestas);

        if ($siguiente === null) {
            throw new ErrorDeConexion('sin red');
        }

        return $siguiente;
    }

    /** @param  array<string, mixed>  $json */
    public static function json(int $estado, array $json, array $cabeceras = []): Respuesta
    {
        return new Respuesta($estado, $cabeceras, (string) json_encode($json));
    }
}
