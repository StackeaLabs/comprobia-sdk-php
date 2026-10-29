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
 *   su `Retry-After`), pero SÓLO donde repetir no duplica nada: lecturas y lo
 *   que lleva `Idempotency-Key`. Un POST sin clave (crear un webhook, rotar su
 *   secreto, pedir una exportación) no se repite ante un corte o un 5xx. El
 *   429 sí, siempre: no se procesó. Un 4xx que no es 429 no se reintenta.
 * - **Errores**: cualquier respuesta que no sea 2xx lanza ErrorDeComprobia con
 *   el `code` estable, el `request_id` y el detalle por campo.
 *
 * Todo devuelve el JSON de la API como array, tal cual: los importes son
 * TEXTO ("118.00") y conviene dejarlos así (un float pierde céntimos).
 */
final class Cliente
{
    public const BASE = 'https://comprobia.stackealabs.com';

    private readonly Peticiones $peticiones;

    /** @param  (Closure(float): void)|null  $esperar  para los tests; por defecto, usleep */
    public function __construct(
        #[\SensitiveParameter] string $clave,
        private readonly string $ruc,
        string $base = self::BASE,
        ?Transporte $transporte = null,
        ?Closure $esperar = null,
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
        // No cambia nada: se puede repetir.
        return $this->solicitar('POST', '/comprobantes/vista-previa', $comprobante, [], true)->cuerpo;
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

    /**
     * Reenvía los mismos bytes: repetirlo no hace otro comprobante, así que se reintenta.
     *
     * @return array<string, mixed>
     */
    public function reenviar(string $id): array
    {
        return $this->json('POST', "/comprobantes/{$id}/reenviar", null, [], true);
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
        return $this->json('POST', "/notas-venta/{$id}/convertir", $datos, [], true);
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
     * El documento va en el cuerpo, no en la URL: un DNI no queda en ningún
     * registro de acceso. Es una lectura, así que se reintenta.
     *
     * @param  'ruc'|'dni'  $tipo
     * @return array<string, mixed>
     */
    public function buscarCliente(string $tipo, string $numero): array
    {
        return $this->json('POST', '/clientes/buscar', ['tipo' => $tipo, 'numero' => $numero], [], true);
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
    public function json(string $metodo, string $ruta, ?array $cuerpo = null, array $cabeceras = [], bool $reintentable = false): array
    {
        return $this->solicitar($metodo, $ruta, $cuerpo, $cabeceras, $reintentable)->json();
    }

    /**
     * Relativa a `/api/v1/{ruc}`. `$reintentable` en un POST que no cambia nada
     * (una búsqueda): las lecturas y lo que lleva `Idempotency-Key` se
     * reintentan siempre.
     *
     * @param  array<string, mixed>|null  $cuerpo
     * @param  array<string, string>  $cabeceras
     *
     * @throws ErrorDeComprobia
     * @throws ErrorDeConexion
     */
    public function solicitar(string $metodo, string $ruta, ?array $cuerpo = null, array $cabeceras = [], bool $reintentable = false): Respuesta
    {
        return $this->peticion($metodo, "/api/v1/{$this->ruc}".$ruta, $cuerpo, $cabeceras, $reintentable);
    }

    /**
     * Cualquier ruta del servicio, desde la raíz: `/api/v1/catalogos/03`,
     * `/api/v1/health`.
     *
     * @param  array<string, mixed>|null  $cuerpo
     * @param  array<string, string>  $cabeceras
     *
     * @throws ErrorDeComprobia
     * @throws ErrorDeConexion
     */
    public function peticion(string $metodo, string $rutaAbsoluta, ?array $cuerpo = null, array $cabeceras = [], bool $reintentable = false): Respuesta
    {
        return $this->peticiones->enviar($metodo, $rutaAbsoluta, $cuerpo, $cabeceras, $reintentable);
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

    private static function claveNueva(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
