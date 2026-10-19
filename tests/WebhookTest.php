<?php

declare(strict_types=1);

namespace Comprobia\Tests;

use Comprobia\Webhook;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class WebhookTest extends TestCase
{
    private const AHORA = 1_790_000_000;

    private static function firma(string $secreto, string $cuerpo, int $ts): string
    {
        return 'sha256='.hash_hmac('sha256', "{$ts}.{$cuerpo}", $secreto);
    }

    public function test_una_firma_buena_vale_y_otra_no(): void
    {
        $cuerpo = '{"id":"e1","event":"comprobante.aceptado","emisor":{"ruc":"20123456789"},"data":{}}';
        $firma = self::firma('whsec_a', $cuerpo, self::AHORA);

        self::assertTrue(Webhook::verificar('whsec_a', $cuerpo, $firma, self::AHORA, ahora: self::AHORA));
        self::assertFalse(Webhook::verificar('whsec_otro', $cuerpo, $firma, self::AHORA, ahora: self::AHORA));
        self::assertFalse(Webhook::verificar('whsec_a', $cuerpo.' ', $firma, self::AHORA, ahora: self::AHORA));
    }

    public function test_durante_la_rotacion_vale_cualquiera_de_las_dos(): void
    {
        $cuerpo = '{"id":"e2"}';
        $cabecera = self::firma('whsec_nuevo', $cuerpo, self::AHORA).','.self::firma('whsec_viejo', $cuerpo, self::AHORA);

        self::assertTrue(Webhook::verificar('whsec_nuevo', $cuerpo, $cabecera, self::AHORA, ahora: self::AHORA));
        self::assertTrue(Webhook::verificar('whsec_viejo', $cuerpo, $cabecera, self::AHORA, ahora: self::AHORA));
    }

    public function test_un_aviso_viejo_no_vale_aunque_la_firma_sea_buena(): void
    {
        $cuerpo = '{"id":"e3"}';
        $viejo = self::AHORA - 301;

        self::assertFalse(Webhook::verificar('whsec_a', $cuerpo, self::firma('whsec_a', $cuerpo, $viejo), $viejo, ahora: self::AHORA));
        self::assertFalse(Webhook::verificar('whsec_a', $cuerpo, self::firma('whsec_a', $cuerpo, self::AHORA), 'no-es-numero', ahora: self::AHORA));
    }

    public function test_leer_devuelve_el_aviso_o_lanza(): void
    {
        $cuerpo = '{"id":"e4","event":"ping","emisor":{"ruc":"20123456789"},"data":{"mensaje":"hola"}}';

        $aviso = Webhook::leer('whsec_a', $cuerpo, self::firma('whsec_a', $cuerpo, self::AHORA), (string) self::AHORA, ahora: self::AHORA);

        self::assertSame('ping', $aviso['event']);
        self::assertSame('20123456789', $aviso['emisor']['ruc']);

        $this->expectException(UnexpectedValueException::class);
        Webhook::leer('whsec_otro', $cuerpo, self::firma('whsec_a', $cuerpo, self::AHORA), self::AHORA, ahora: self::AHORA);
    }
}
