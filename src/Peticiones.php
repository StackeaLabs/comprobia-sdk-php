<?php

declare(strict_types=1);

namespace Comprobia;

use Closure;

/**
 * El bucle de una petición con sus reintentos, común a Cliente y Partner.
 *
 * Reintenta (hasta 3 intentos) un corte de red o un 5xx SÓLO donde repetir no
 * puede duplicar nada: un GET, algo que lleva `Idempotency-Key`, o una
 * petición marcada como reintentable (una búsqueda por POST). Un POST sin
 * clave —crear un webhook, rotar su secreto, pedir una exportación— no se
 * repite: el servidor pudo haberlo hecho. El 429 se reintenta siempre, porque
 * no se procesó.
 *
 * @internal
 */
final class Peticiones
{
    private const INTENTOS = 3;

    /** @param  Closure(float): void  $esperar */
    public function __construct(
        #[\SensitiveParameter] private readonly string $clave,
        private readonly string $base,
        private readonly Transporte $transporte,
        private readonly Closure $esperar,
    ) {}

    /**
     * @param  array<string, mixed>|null  $cuerpo
     * @param  array<string, string>  $cabeceras
     *
     * @throws ErrorDeComprobia
     * @throws ErrorDeConexion
     */
    public function enviar(string $metodo, string $rutaAbsoluta, ?array $cuerpo, array $cabeceras, bool $reintentable): Respuesta
    {
        $url = rtrim($this->base, '/').$rutaAbsoluta;
        $cabeceras = [
            'Authorization' => "Bearer {$this->clave}",
            'Accept' => 'application/json',
            'User-Agent' => 'comprobia-php/'.Version::ACTUAL,
            ...($cuerpo === null ? [] : ['Content-Type' => 'application/json']),
            ...$cabeceras,
        ];
        $json = $cuerpo === null ? null : json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $seguro = $reintentable || in_array($metodo, ['GET', 'HEAD'], true) || isset($cabeceras['Idempotency-Key']);

        for ($intento = 1; ; $intento++) {
            try {
                $respuesta = $this->transporte->enviar($metodo, $url, $cabeceras, $json);
            } catch (ErrorDeConexion $e) {
                if (! $seguro || $intento >= self::INTENTOS) {
                    $e->claveIdempotencia = $cabeceras['Idempotency-Key'] ?? null;

                    throw $e;
                }

                ($this->esperar)(self::espera($intento, null));

                continue;
            }

            if ($respuesta->estado < 400) {
                return $respuesta;
            }

            $repetir = $respuesta->estado === 429 || ($respuesta->estado >= 500 && $seguro);

            if (! $repetir || $intento >= self::INTENTOS) {
                throw ErrorDeComprobia::desde($respuesta);
            }

            ($this->esperar)(self::espera($intento, $respuesta->cabecera('Retry-After')));
        }
    }

    /** 1 s, 2 s… o lo que diga Retry-After, con un techo de 10 s. */
    private static function espera(int $intento, ?string $retryAfter): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return min((float) $retryAfter, 10.0);
        }

        return (float) (2 ** ($intento - 1));
    }
}
