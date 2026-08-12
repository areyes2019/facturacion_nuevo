<x-mail::message>
# Cotización {{ $cotizacion->folio }}

Hola,

Adjuntamos la cotización correspondiente a **{{ $cotizacion->cliente->razon_social }}**, por un total de
**${{ number_format($cotizacion->total, 2) }} MXN**.

Encontrarás el PDF adjunto a este correo.

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
