<?php

declare(strict_types=1);

namespace Comprobia;

use JsonException;
use UnexpectedValueException;

/**
 * Verificar y leer un webhook de Comprobia.
 *
 *   $aviso = Webhook::leer(
 *       $secreto,                                  // whsec_…, el de tu webhook
 *       file_get_contents('php://input'),          // el cuerpo CRUDO
 *       $_SERVER['HTTP_X_COMPROBIA_SIGNATURE'] ?? '',
 *       $_SERVER['HTTP_X_COMPROBIA_TIMESTAMP'] ?? '',
 *   );
 *   // $aviso['event'], $aviso['emisor']['ruc'], $aviso['data']
 *
 * La firma es `sha256=` + HMAC-SHA256 de `{timestamp}.{cuerpo}`. Durante las
 * 24 h siguientes a rotar el secreto llegan DOS, separadas por coma: vale con
 * que una coincida. Un timestamp de hace más de 5 minutos se rechaza (un
 * tercero no puede reenviarte un aviso viejo).
 *
 * Descarta los repetidos por `id`: un aviso puede llegar más de una vez.
 */
final class Webhook
{
    public const TOLERANCIA = 300;

    public static function verificar(
        #[\SensitiveParameter] string $secreto,
        string $cuerpo,
        string $firmas,
        string|int $timestamp,
        int $tolerancia = self::TOLERANCIA,
        ?int $ahora = null,
    ): bool {
        if (! is_numeric((string) $timestamp)) {
            return false;
        }

        $ts = (int) $timestamp;

        if (abs(($ahora ?? time()) - $ts) > $tolerancia) {
            return false;
        }

        $esperada = 'sha256='.hash_hmac('sha256', "{$ts}.{$cuerpo}", $secreto);

        foreach (explode(',', $firmas) as $firma) {
            if (hash_equals($esperada, trim($firma))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica y devuelve el aviso.
     *
     * @return array<string, mixed>
     *
     * @throws UnexpectedValueException si la firma no vale o el cuerpo no es JSON
     */
    public static function leer(
        #[\SensitiveParameter] string $secreto,
        string $cuerpo,
        string $firmas,
        string|int $timestamp,
        int $tolerancia = self::TOLERANCIA,
        ?int $ahora = null,
    ): array {
        if (! self::verificar($secreto, $cuerpo, $firmas, $timestamp, $tolerancia, $ahora)) {
            throw new UnexpectedValueException('La firma del webhook no es válida o el aviso es demasiado viejo.');
        }

        try {
            $aviso = json_decode($cuerpo, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('El cuerpo del webhook no es JSON.');
        }

        if (! is_array($aviso)) {
            throw new UnexpectedValueException('El cuerpo del webhook no es un objeto.');
        }

        return $aviso;
    }
}
