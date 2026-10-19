<?php

declare(strict_types=1);

namespace Comprobia;

use Closure;
use Generator;

/**
 * Cliente fino de la API v1 de Comprobia (https://comprobia.stackealabs.com/docs).
 *
 *   $comprobia = new Cliente('cmp_beta_…', '20123456789');
 *   $r = $comprobia->emitir([...]);          // 202: número, XML firmado, consulta_publica
 *
 * Lo que hace por ti:
 *
 * - **Idempotencia**: cada emisión y anulación lleva una `Idempotency-Key`. Si
 *   no pasas una, la genera; si la red se corta, reintenta con LA MISMA, así
 *   que un comprobante nunca sale dos veces. Para reintentar tú más tarde,
 *   guarda la tuya (por ejemplo, el id de la venta) y pásala.
 * - **Reintentos**: hasta 3 intentos ante un corte de red, un 5xx o un 429 (con
 *   su `Retry-After`). Un 4xx que no es 429 no se reintenta: hay que corregir.
 * - **Errores**: cualquier respuesta que no sea 2xx lanza ErrorDeComprobia con
 *   el `code` estable, el `request_id` y el detalle por campo.
 *
 * Todo devuelve el JSON de la API como array, tal cual: los importes son
 * TEXTO ("118.00") y conviene dejarlos así (un float pierde céntimos).
 */
final class Cliente
{
    public const BASE = 'https://comprobia.stackealabs.com';

    private const INTENTOS = 3;

    private readonly Transporte $transporte;

    /** @var Closure(float): void */
    private readonly Closure $esperar;

    /** @param  (Closure(float): void)|null  $esperar  para los tests; por defecto, usleep */
    public function __construct(
        #[\SensitiveParameter] private readonly string $clave,
        private readonly string $ruc,
        private readonly string $base = self::BASE,
        ?Transporte $transporte = null,
        ?Closure $esperar = null,
    ) {
        $this->transporte = $transporte ?? new TransporteCurl;
        $this->esperar = $esperar ?? static function (float $segundos): void {
            usleep((int) ($segundos * 1_000_000));
        };
    }

    // ── Comprobantes ──────────────────────────────────────────────────────

    /**
     * POST /comprobantes → 202. Factura, boleta o nota.
     *
     * @param  array<string, mixed>  $comprobante
     * @return array<string, mixed>
     */
    public function emitir(array $comprobante, ?string $claveIdempotencia = null): array
    {
        return $this->conIdempotencia('POST', '/comprobantes', $comprobante, $claveIdempotencia);
    }

    /**
     * POST /comprobantes/lote → hasta 50. La idempotencia es por comprobante
     * (`{clave}#{n}`): reintentar un lote a medias no duplica la primera mitad.
     *
     * @param  list<array<string, mixed>>  $comprobantes
     * @return array<string, mixed>
     */
    public function emitirLote(array $comprobantes, ?string $claveIdempotencia = null): array
    {
        return $this->conIdempotencia('POST', '/comprobantes/lote', ['comprobantes' => $comprobantes], $claveIdempotencia);
    }

    /**
     * POST /comprobantes/vista-previa → el PDF tal como saldría, sin gastar número.
     *
     * @param  array<string, mixed>  $comprobante
     */
    public function vistaPrevia(array $comprobante): string
    {
        return $this->solicitar('POST', '/comprobantes/vista-previa', $comprobante)->cuerpo;
    }

    /** @return array<string, mixed> */
    public function comprobante(string $id): array
    {
        return $this->json('GET', "/comprobantes/{$id}");
    }

    /**
     * Una página del listado. Filtros: tipo_documento, serie, estado, desde,
     * hasta, external_id, numero (F001-123), cliente_documento,
     * actualizado_desde, por_pagina, cursor.
     *
     * @param  array<string, scalar>  $filtros
     * @return array{data: list<array<string, mixed>>, siguiente_cursor: ?string}
     */
    public function comprobantes(array $filtros = []): array
    {
        /** @var array{data: list<array<string, mixed>>, siguiente_cursor: ?string} */
        return $this->json('GET', '/comprobantes'.self::query($filtros));
    }

    /**
     * Todos los comprobantes que cumplen los filtros, página a página.
     *
     * @param  array<string, scalar>  $filtros
     * @return Generator<int, array<string, mixed>>
     */
    public function recorrerComprobantes(array $filtros = []): Generator
    {
        return $this->recorrer('/comprobantes', $filtros);
    }

    /** El comprobante cuyo `external_id` es el tuyo, o null. */
    public function comprobantePorExternalId(string $externalId): ?array
    {
        $pagina = $this->comprobantes(['external_id' => $externalId]);

        return $pagina['data'][0] ?? null;
    }

    /**
     * POST /comprobantes/{id}/anular → 202. La factura va por comunicación de
     * baja y la boleta por resumen diario: lo decide Comprobia. La anulación
     * vale cuando SUNAT la acepta (webhook `comprobante.anulado`).
     *
     * @return array<string, mixed>
     */
    public function anular(string $id, string $motivo, ?string $claveIdempotencia = null): array
    {
        return $this->conIdempotencia('POST', "/comprobantes/{$id}/anular", ['motivo' => $motivo], $claveIdempotencia);
    }

    /** @return array<string, mixed> */
    public function reenviar(string $id): array
    {
        return $this->json('POST', "/comprobantes/{$id}/reenviar");
    }

    /** @param  'a4'|'a5'|'ticket'|'ticket58'|'ticket50'  $formato */
    public function pdf(string $id, string $formato = 'a4'): string
    {
        return $this->solicitar('GET', "/comprobantes/{$id}/pdf?formato={$formato}")->cuerpo;
    }

    public function xml(string $id): string
    {
        return $this->solicitar('GET', "/comprobantes/{$id}/xml")->cuerpo;
    }

    public function cdr(string $id): string
    {
        return $this->solicitar('GET', "/comprobantes/{$id}/cdr")->cuerpo;
    }

    // ── Notas de venta (sin valor fiscal) ─────────────────────────────────

    /**
     * 201 con la nota ya terminada: no va a SUNAT.
     *
     * @param  array<string, mixed>  $nota
     * @return array<string, mixed>
     */
    public function emitirNotaVenta(array $nota, ?string $claveIdempotencia = null): array
    {
        return $this->conIdempotencia('POST', '/notas-venta', $nota, $claveIdempotencia);
    }

    /** @return array<string, mixed> */
    public function notaVenta(string $id): array
    {
        return $this->json('GET', "/notas-venta/{$id}");
    }

    /**
     * @param  array<string, scalar>  $filtros
     * @return array{data: list<array<string, mixed>>, siguiente_cursor: ?string}
     */
    public function notasVenta(array $filtros = []): array
    {
        /** @var array{data: list<array<string, mixed>>, siguiente_cursor: ?string} */
        return $this->json('GET', '/notas-venta'.self::query($filtros));
    }

    /**
     * La boleta (03) o factura (01) que la sustituye. Sin Idempotency-Key: la
     * fija la nota, así que reintentar devuelve el mismo comprobante.
     *
     * @param  array{tipo_documento: string, serie: string, fecha_emision?: string, cliente?: array<string, string>}  $datos
     * @return array{nota_venta: array<string, mixed>, comprobante: array<string, mixed>}
     */
    public function convertirNotaVenta(string $id, array $datos): array
    {
        /** @var array{nota_venta: array<string, mixed>, comprobante: array<string, mixed>} */
        return $this->json('POST', "/notas-venta/{$id}/convertir", $datos);
    }

    /** @return array<string, mixed> */
    public function anularNotaVenta(string $id, string $motivo): array
    {
        return $this->json('POST', "/notas-venta/{$id}/anular", ['motivo' => $motivo]);
    }

    /** @param  'a4'|'ticket'  $formato */
    public function pdfNotaVenta(string $id, string $formato = 'a4'): string
    {
        return $this->solicitar('GET', "/notas-venta/{$id}/pdf?formato={$formato}")->cuerpo;
    }

    // ── Guías, clientes, consumo ──────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $guia
     * @return array<string, mixed>
     */
    public function emitirGuia(array $guia, ?string $claveIdempotencia = null): array
    {
        return $this->conIdempotencia('POST', '/guias', $guia, $claveIdempotencia);
    }

    /** @return array<string, mixed> */
    public function guia(string $id): array
    {
        return $this->json('GET', "/guias/{$id}");
    }

    /**
     * La ficha de un RUC o DNI para autocompletar (no rechaza nada: avisa).
     *
     * @param  'ruc'|'dni'  $tipo
     * @return array<string, mixed>
     */
    public function buscarCliente(string $tipo, string $numero): array
    {
        return $this->json('GET', "/clientes/{$tipo}/{$numero}");
    }

    /** @return array<string, mixed> */
    public function consumo(?string $periodo = null): array
    {
        return $this->json('GET', '/consumo'.self::query($periodo === null ? [] : ['periodo' => $periodo]));
    }

    // ── Lo demás ──────────────────────────────────────────────────────────

    /**
     * Cualquier otra ruta de la v1, relativa a `/api/v1/{ruc}` (por ejemplo
     * `/webhooks`). Devuelve el JSON.
     *
     * @param  array<string, mixed>|null  $cuerpo
     * @param  array<string, string>  $cabeceras
     * @return array<string, mixed>
     */
    public function json(string $metodo, string $ruta, ?array $cuerpo = null, array $cabeceras = []): array
    {
        return $this->solicitar($metodo, $ruta, $cuerpo, $cabeceras)->json();
    }

    /**
     * @param  array<string, mixed>|null  $cuerpo
     * @param  array<string, string>  $cabeceras
     *
     * @throws ErrorDeComprobia
     * @throws ErrorDeConexion
     */
    public function solicitar(string $metodo, string $ruta, ?array $cuerpo = null, array $cabeceras = []): Respuesta
    {
        $url = rtrim($this->base, '/')."/api/v1/{$this->ruc}".$ruta;
        $cabeceras = [
            'Authorization' => "Bearer {$this->clave}",
            'Accept' => 'application/json',
            'User-Agent' => 'comprobia-php/'.Version::ACTUAL,
            ...($cuerpo === null ? [] : ['Content-Type' => 'application/json']),
            ...$cabeceras,
        ];
        $json = $cuerpo === null ? null : json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        for ($intento = 1; ; $intento++) {
            try {
                $respuesta = $this->transporte->enviar($metodo, $url, $cabeceras, $json);
            } catch (ErrorDeConexion $e) {
                if ($intento >= self::INTENTOS) {
                    $e->claveIdempotencia = $cabeceras['Idempotency-Key'] ?? null;

                    throw $e;
                }

                ($this->esperar)(self::espera($intento, null));

                continue;
            }

            if ($respuesta->estado < 400) {
                return $respuesta;
            }

            $reintentable = $respuesta->estado === 429 || $respuesta->estado >= 500;

            if (! $reintentable || $intento >= self::INTENTOS) {
                throw ErrorDeComprobia::desde($respuesta);
            }

            ($this->esperar)(self::espera($intento, $respuesta->cabecera('Retry-After')));
        }
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    private function conIdempotencia(string $metodo, string $ruta, array $cuerpo, ?string $clave): array
    {
        return $this->json($metodo, $ruta, $cuerpo, ['Idempotency-Key' => $clave ?? self::claveNueva()]);
    }

    /**
     * @param  array<string, scalar>  $filtros
     * @return Generator<int, array<string, mixed>>
     */
    private function recorrer(string $ruta, array $filtros): Generator
    {
        $cursor = null;

        do {
            $pagina = $this->json('GET', $ruta.self::query([...$filtros, ...($cursor === null ? [] : ['cursor' => $cursor])]));

            foreach ((array) ($pagina['data'] ?? []) as $fila) {
                yield $fila;
            }

            $cursor = $pagina['siguiente_cursor'] ?? null;
        } while (is_string($cursor) && $cursor !== '');
    }

    /** @param  array<string, scalar>  $filtros */
    private static function query(array $filtros): string
    {
        $filtros = array_filter($filtros, static fn (mixed $v): bool => $v !== null && $v !== '');

        return $filtros === [] ? '' : '?'.http_build_query($filtros);
    }

    /** 1 s, 2 s… o lo que diga Retry-After, con un techo de 10 s. */
    private static function espera(int $intento, ?string $retryAfter): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return min((float) $retryAfter, 10.0);
        }

        return (float) (2 ** ($intento - 1));
    }

    private static function claveNueva(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
