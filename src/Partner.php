<?php

declare(strict_types=1);

namespace Comprobia;

use Closure;

/**
 * La API de partners (`/api/partner/v1`), para quien opera los RUC de sus
 * clientes con una sola clave (`cmpp_…`).
 *
 *   $partner = new Partner('cmpp_produccion_…');
 *   $partner->emisores();                               // tu cartera
 *   $partner->emisor('20100066603')->emitir([...]);     // la v1, con tu clave
 */
final class Partner
{
    private readonly Peticiones $peticiones;

    /** @param  (Closure(float): void)|null  $esperar  para los tests; por defecto, usleep */
    public function __construct(
        #[\SensitiveParameter] private readonly string $clave,
        private readonly string $base = Cliente::BASE,
        private readonly ?Transporte $transporte = null,
        private readonly ?Closure $esperar = null,
    ) {
        $this->peticiones = new Peticiones(
            $clave,
            $base,
            $transporte ?? new TransporteCurl,
            $esperar ?? static function (float $segundos): void {
                usleep((int) ($segundos * 1_000_000));
            },
        );
    }

    /** Un cliente de la v1 para un RUC de tu cartera, con la clave del partner. */
    public function emisor(string $ruc): Cliente
    {
        return new Cliente($this->clave, $ruc, $this->base, $this->transporte, $this->esperar);
    }

    /** @return array<string, mixed> */
    public function emisores(): array
    {
        return $this->json('GET', '/emisores');
    }

    /** @return array<string, mixed> */
    public function consumo(?string $periodo = null): array
    {
        return $this->json('GET', '/consumo'.($periodo === null ? '' : '?'.http_build_query(['periodo' => $periodo])));
    }

    /**
     * Cualquier ruta de la API de partners, relativa a `/api/partner/v1`.
     *
     * @param  array<string, mixed>|null  $cuerpo
     * @param  array<string, string>  $cabeceras
     * @return array<string, mixed>
     */
    public function json(string $metodo, string $ruta, ?array $cuerpo = null, array $cabeceras = [], bool $reintentable = false): array
    {
        return $this->peticiones->enviar($metodo, '/api/partner/v1'.$ruta, $cuerpo, $cabeceras, $reintentable)->json();
    }
}
