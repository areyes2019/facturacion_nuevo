<x-mail::message>
# Factura {{ $factura->facturapi_serie }}{{ $factura->facturapi_folio ?? $factura->folio }}

Hola,

Adjuntamos la factura (CFDI) correspondiente a **{{ $factura->cliente->razon_social }}**, por un total de
**${{ number_format($factura->total, 2) }} {{ $factura->moneda }}**.

Encontrarás el archivo XML (comprobante fiscal) y el PDF (representación impresa) adjuntos a este correo.

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
