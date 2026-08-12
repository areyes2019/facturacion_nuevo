@extends('pdf.documento', [
    'titulo' => 'COTIZACIÓN',
    'folio' => (string) $cotizacion->folio,
    'fecha' => $cotizacion->created_at,
    // Una cotización no se cancela: su estado siempre se imprime en verde.
    'estadoEtiqueta' => ucfirst(str_replace('_', ' ', $cotizacion->estado->value)),
    'estadoCancelado' => false,
    'lineas' => $cotizacion->lineas,
    'documento' => $cotizacion,
    'etiquetaPrecio' => 'Precio unitario',
    'moneda' => 'MXN',
    'notaPie' => 'Este documento no es un comprobante fiscal (CFDI)',
])

@section('contraparte')
    <p class="parte-titulo">Cliente</p>
    <p><strong>{{ $cotizacion->cliente->razon_social }}</strong></p>
    <p>RFC: <strong>{{ $cotizacion->cliente->rfc }}</strong></p>
    @if ($cotizacion->cliente->direccion_comercial)
        <p>{{ $cotizacion->cliente->direccion_comercial }}</p>
    @endif
    <p>Código postal: {{ $cotizacion->cliente->codigo_postal_fiscal }}</p>
    <p>Régimen fiscal: {{ $sat->regimenFiscal($cotizacion->cliente->regimen_fiscal) }}</p>
    @if ($cotizacion->cliente->correo_contacto)
        <p>Correo: {{ $cotizacion->cliente->correo_contacto }}</p>
    @endif
@endsection
