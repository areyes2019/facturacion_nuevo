<x-mail::message>
# Orden de compra {{ $ordenCompra->folioFormateado() }}

Hola,

Adjuntamos la orden de compra dirigida a **{{ $ordenCompra->proveedor->nombre_comercial }}**, por un total de
**${{ number_format($ordenCompra->total, 2) }} MXN**.

@if ($ordenCompra->fecha_entrega_esperada)
Fecha de entrega esperada: **{{ $ordenCompra->fecha_entrega_esperada->format('d/m/Y') }}**.
@endif

Encontrarás el PDF adjunto a este correo.

Saludos,<br>
{{ config('app.name') }}
</x-mail::message>
