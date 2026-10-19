<?php

declare(strict_types=1);

namespace Comprobia\Tests;

use Comprobia\Cliente;
use Comprobia\ErrorDeComprobia;
use Comprobia\ErrorDeConexion;
use Comprobia\Respuesta;
use PHPUnit\Framework\TestCase;

final class ClienteTest extends TestCase
{
    /** @var list<float> */
    private array $esperas = [];

    private function cliente(TransporteDePrueba $t): Cliente
    {
        return new Cliente('cmp_beta_x', '20123456789', 'https://comprobia.test', $t, function (float $s): void {
            $this->esperas[] = $s;
        });
    }

    public function test_emitir_manda_la_clave_la_url_y_una_idempotency_key(): void
    {
        $t = new TransporteDePrueba([TransporteDePrueba::json(202, ['numero_completo' => 'F001-1'])]);

        $r = $this->cliente($t)->emitir(['tipo_documento' => '01', 'serie' => 'F001']);

        $p = $t->peticiones[0];
        self::assertSame('F001-1', $r['numero_completo']);
        self::assertSame('POST', $p['metodo']);
        self::assertSame('https://comprobia.test/api/v1/20123456789/comprobantes', $p['url']);
        self::assertSame('Bearer cmp_beta_x', $p['cabeceras']['Authorization']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $p['cabeceras']['Idempotency-Key']);
        self::assertSame('{"tipo_documento":"01","serie":"F001"}', $p['cuerpo']);
    }

    public function test_un_corte_de_red_se_reintenta_con_la_misma_idempotency_key(): void
    {
        $t = new TransporteDePrueba([null, TransporteDePrueba::json(503, ['code' => 'INTERNAL_ERROR', 'message' => 'x']), TransporteDePrueba::json(202, ['id' => 'a'])]);

        $this->cliente($t)->emitir(['serie' => 'F001'], 'venta-123');

        self::assertCount(3, $t->peticiones);
        self::assertSame(['venta-123', 'venta-123', 'venta-123'], array_map(static fn (array $p): string => $p['cabeceras']['Idempotency-Key'], $t->peticiones));
        self::assertSame([1.0, 2.0], $this->esperas);
    }

    public function test_el_429_respeta_retry_after(): void
    {
        $t = new TransporteDePrueba([
            TransporteDePrueba::json(429, ['code' => 'RATE_LIMITED', 'message' => 'x'], ['retry-after' => '7']),
            TransporteDePrueba::json(200, ['data' => []]),
        ]);

        $this->cliente($t)->comprobantes();

        self::assertSame([7.0], $this->esperas);
    }

    public function test_un_4xx_no_se_reintenta_y_trae_el_codigo_el_request_id_y_el_detalle(): void
    {
        $t = new TransporteDePrueba([TransporteDePrueba::json(422, [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Hay errores.',
            'request_id' => 'req-1',
            'errors' => [['field' => 'items.0.cantidad', 'rule' => 'RN-C1', 'message' => 'Positiva.']],
        ])]);

        try {
            $this->cliente($t)->emitir([]);
            self::fail('Debía lanzar.');
        } catch (ErrorDeComprobia $e) {
            self::assertSame(422, $e->estado);
            self::assertSame('VALIDATION_ERROR', $e->codigo);
            self::assertSame('req-1', $e->requestId);
            self::assertSame('items.0.cantidad', $e->errores[0]['field']);
            self::assertCount(1, $t->peticiones);
        }
    }

    public function test_si_la_red_no_vuelve_el_error_dice_que_clave_reusar(): void
    {
        $t = new TransporteDePrueba([null, null, null]);

        try {
            $this->cliente($t)->emitir([], 'venta-9');
            self::fail('Debía lanzar.');
        } catch (ErrorDeConexion $e) {
            self::assertSame('venta-9', $e->claveIdempotencia);
            self::assertCount(3, $t->peticiones);
        }
    }

    public function test_recorrer_sigue_el_cursor_hasta_el_final(): void
    {
        $t = new TransporteDePrueba([
            TransporteDePrueba::json(200, ['data' => [['id' => '1'], ['id' => '2']], 'siguiente_cursor' => 'abc']),
            TransporteDePrueba::json(200, ['data' => [['id' => '3']], 'siguiente_cursor' => null]),
        ]);

        $ids = array_map(static fn (array $c): string => $c['id'], iterator_to_array($this->cliente($t)->recorrerComprobantes(['estado' => 'ACEPTADO']), false));

        self::assertSame(['1', '2', '3'], $ids);
        self::assertStringEndsWith('/comprobantes?estado=ACEPTADO', $t->peticiones[0]['url']);
        self::assertStringEndsWith('/comprobantes?estado=ACEPTADO&cursor=abc', $t->peticiones[1]['url']);
    }

    public function test_por_external_id_y_los_binarios(): void
    {
        $t = new TransporteDePrueba([
            TransporteDePrueba::json(200, ['data' => [['id' => 'x', 'external_id' => 'V-1']], 'siguiente_cursor' => null]),
            TransporteDePrueba::json(200, ['data' => [], 'siguiente_cursor' => null]),
            new Respuesta(200, ['content-type' => 'application/pdf'], '%PDF-1.7'),
        ]);
        $c = $this->cliente($t);

        self::assertSame('x', $c->comprobantePorExternalId('V-1')['id'] ?? null);
        self::assertNull($c->comprobantePorExternalId('V-2'));
        self::assertSame('%PDF-1.7', $c->pdf('x', 'ticket'));
        self::assertStringEndsWith('/comprobantes/x/pdf?formato=ticket', $t->peticiones[2]['url']);
    }

    public function test_anular_lleva_el_motivo_y_su_idempotency_key(): void
    {
        $t = new TransporteDePrueba([TransporteDePrueba::json(202, ['tipo' => 'COMUNICACION_BAJA'])]);

        $this->cliente($t)->anular('abc', 'ERROR EN EL RUC', 'anular-abc');

        self::assertStringEndsWith('/comprobantes/abc/anular', $t->peticiones[0]['url']);
        self::assertSame('{"motivo":"ERROR EN EL RUC"}', $t->peticiones[0]['cuerpo']);
        self::assertSame('anular-abc', $t->peticiones[0]['cabeceras']['Idempotency-Key']);
    }
}
