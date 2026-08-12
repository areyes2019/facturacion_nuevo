@extends('pdf.documento', [
    'titulo' => 'ORDEN DE COMPRA',
    'folio' => $orden->folioFormateado(),
    'fecha' => $orden->created_at,
    // Una orden de compra no se cancela: su estado siempre se imprime en verde.
    'estadoEtiqueta' => ucfirst($orden->estado->value),
    'estadoCancelado' => false,
    'lineas' => $orden->lineas,
    'documento' => $orden,
    // La única diferencia de la tabla entre los tres documentos: aquí no se vende, se compra.
    'etiquetaPrecio' => 'Costo unitario',
    'moneda' => 'MXN',
    'notaPie' => 'Este documento no es un comprobante fiscal (CFDI)',
])

@section('meta-extra')
    @if ($orden->fecha_entrega_esperada)
        <p>Entrega esperada: {{ $orden->fecha_entrega_esperada->format('d/m/Y') }}</p>
    @endif
@endsection

@section('contraparte')
    <p class="parte-titulo">Proveedor</p>
    <p><strong>{{ $orden->proveedor->nombre_comercial }}</strong></p>
    @if ($orden->proveedor->rfc)
        <p>RFC: <strong>{{ $orden->proveedor->rfc }}</strong></p>
    @endif
    @if ($orden->proveedor->nombre_contacto)
        <p>Atención: {{ $orden->proveedor->nombre_contacto }}</p>
    @endif
    @if ($orden->proveedor->correo)
        <p>Correo: {{ $orden->proveedor->correo }}</p>
    @endif
    @if ($orden->proveedor->telefono)
        <p>Teléfono: {{ $orden->proveedor->telefono }}</p>
    @endif
@endsection

@section('extras')
    @if ($orden->observaciones)
        <div class="caja">
            <h2>Observaciones</h2>
            <p>{{ $orden->observaciones }}</p>
        </div>
    @endif
@endsection
