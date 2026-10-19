<?php

declare(strict_types=1);

namespace Comprobia;

/** Lo que contestó la API, sin interpretar. */
final class Respuesta
{
    /** @param  array<string, string>  $cabeceras  con los nombres en minúsculas */
    public function __construct(
        public readonly int $estado,
        public readonly array $cabeceras,
        public readonly string $cuerpo,
    ) {}

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->cuerpo === '') {
            return [];
        }

        $datos = json_decode($this->cuerpo, true);

        return is_array($datos) ? $datos : [];
    }
}
