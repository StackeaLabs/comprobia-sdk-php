# Comprobia para PHP

Cliente fino de la API v1 de [Comprobia](https://comprobia.stackealabs.com): facturación electrónica SUNAT por API. Sin dependencias, sólo `ext-curl` y `ext-json`. PHP 8.1 o más.

La referencia completa de la API está en <https://comprobia.stackealabs.com/docs>.

## Instalar

Mientras se publica en Packagist, desde el repositorio:

```bash
composer config repositories.comprobia vcs <url-del-repositorio>
composer require stackealabs/comprobia
```

## Emitir

```php
use Comprobia\Cliente;
use Comprobia\ErrorDeComprobia;

$comprobia = new Cliente(getenv('COMPROBIA_CLAVE'), '20123456789');

try {
    $r = $comprobia->emitir([
        'tipo_documento' => '03',
        'serie' => 'B001',
        'fecha_emision' => date('Y-m-d'),
        'moneda' => 'PEN',
        'cliente' => ['tipo_documento' => '1', 'numero_documento' => '45678912', 'razon_social' => 'MARIA QUISPE'],
        'external_id' => 'VENTA-1842',
        'items' => [[
            'descripcion' => 'Consulta podológica',
            'cantidad' => '1',
            'unidad_medida' => 'ZZ',
            'precio_unitario' => '80.00',
            'tipo_afectacion' => '10',
        ]],
    ], claveIdempotencia: 'VENTA-1842');

    echo $r['numero_completo'];   // B001-1
    echo $r['consulta_publica'];  // el enlace para tu cliente
} catch (ErrorDeComprobia $e) {
    // $e->codigo (estable), $e->getMessage(), $e->errores (por campo), $e->requestId
}
```

`emitir` responde con el `202` de la API: el comprobante ya tiene número y XML firmado, y el envío a SUNAT va en cola. El veredicto llega por webhook (`comprobante.aceptado`, `comprobante.rechazado`…) o consultando `comprobante($id)`.

## Lo que hace por ti

- **Idempotencia.** Cada emisión y anulación lleva una `Idempotency-Key`. Si no pasas una, la genera. Si la red se corta, reintenta con la misma, así que el comprobante no sale dos veces. Para reintentar tú más tarde, usa una tuya, por ejemplo el id de la venta. Si se agotan los intentos, `ErrorDeConexion::$claveIdempotencia` dice cuál reusar.
- **Reintentos.** Hasta 3 intentos ante un corte de red, un 5xx o un 429 (respetando `Retry-After`). Un 4xx distinto de 429 no se reintenta: hay que corregir el cuerpo.
- **Importes como texto.** Todo vuelve como el JSON de la API, con los importes en texto (`"118.00"`). Déjalos así: un `float` pierde céntimos.

## Lo demás

```php
$comprobia->comprobante($id);
$comprobia->comprobantes(['estado' => 'ACEPTADO', 'desde' => '2026-10-01']);   // una página
foreach ($comprobia->recorrerComprobantes(['actualizado_desde' => '2026-10-18T00:00:00-05:00']) as $c) { /* … */ }
$comprobia->comprobantePorExternalId('VENTA-1842');
$comprobia->anular($id, 'ERROR EN EL MONTO');
$comprobia->pdf($id, 'ticket');   // a4, a5, ticket, ticket58, ticket50
$comprobia->xml($id);
$comprobia->cdr($id);
$comprobia->emitirLote([...]);    // hasta 50
$comprobia->emitirGuia([...]);
$comprobia->buscarCliente('ruc', '20100066603');
$comprobia->consumo('2026-10');

// Cualquier otra ruta de la v1:
$comprobia->json('GET', '/webhooks');
```

## Webhooks

```php
use Comprobia\Webhook;

try {
    $aviso = Webhook::leer(
        getenv('COMPROBIA_WEBHOOK_SECRETO'),
        file_get_contents('php://input'),          // el cuerpo CRUDO, sin decodificar
        $_SERVER['HTTP_X_COMPROBIA_SIGNATURE'] ?? '',
        $_SERVER['HTTP_X_COMPROBIA_TIMESTAMP'] ?? '',
    );
} catch (UnexpectedValueException) {
    http_response_code(401);
    exit;
}

// $aviso['id'] (descarta repetidos), $aviso['event'], $aviso['emisor']['ruc'], $aviso['data']
http_response_code(200);
```

Tras rotar el secreto, durante 24 h llegan dos firmas y vale cualquiera de las dos, así que puedes desplegar el secreto nuevo sin perder avisos. Un aviso con más de 5 minutos se rechaza.

## Tests

```bash
composer install
composer test
```
