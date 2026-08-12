@php
    $cancelada = $factura->estado->value === 'cancelada';
    $serieFolio = trim(($factura->facturapi_serie ?? '').($factura->facturapi_folio ?? $factura->folio));
@endphp

@extends('pdf.documento', [
    'titulo' => 'FACTURA',
    'folio' => $serieFolio,
    'fecha' => $factura->fecha_timbrado ?? $factura->created_at,
    'estadoEtiqueta' => $cancelada ? 'Cancelada' : 'Vigente',
    'estadoCancelado' => $cancelada,
    'lineas' => $factura->lineas,
    'documento' => $factura,
    'etiquetaPrecio' => 'Precio unitario',
    'moneda' => $factura->moneda,
    'notaPie' => 'Este documento es una representación impresa de un CFDI',
])

@section('contraparte')
    <p class="parte-titulo">Receptor</p>
    <p><strong>{{ $factura->cliente->razon_social }}</strong></p>
    <p>RFC: <strong>{{ $factura->cliente->rfc }}</strong></p>
    @if ($factura->cliente->direccion_comercial)
        <p>{{ $factura->cliente->direccion_comercial }}</p>
    @endif
    <p>Código postal: {{ $factura->cliente->codigo_postal_fiscal }}</p>
    <p>Uso del CFDI: {{ $sat->usoCfdi($factura->uso_cfdi) }}</p>
    <p>Régimen fiscal: {{ $sat->regimenFiscal($factura->cliente->regimen_fiscal) }}</p>
    {{-- Forma y método de pago viven aquí y no en una caja aparte: son datos del comprobante que
         describen a esta operación con este receptor (ver 019, "Encabezado, emisor y contraparte"). --}}
    <p>Forma de pago: {{ $sat->formaPago($factura->forma_pago) }}</p>
    <p>Método de pago: {{ $factura->metodo_pago->value }}</p>
    @if ($factura->cliente->correo_contacto)
        <p>Correo: {{ $factura->cliente->correo_contacto }}</p>
    @endif
@endsection

@section('timbre')
    {{-- Solo las facturas timbradas llevan timbre. Una pendiente o con error de timbrado imprime el
         resto del documento, no un bloque vacío. Una cancelada lo conserva completo: el CFDI
         existió y su constancia impresa sigue siendo válida como representación. --}}
    @if ($factura->uuid_fiscal)
        @php($qr = app(\App\Services\QrTimbreFiscal::class)->datos($factura, $emisor))

        <div class="timbre">
            <p class="timbre-titulo">Timbre Fiscal Digital</p>

            <table class="tfd">
                <tr>
                    <td class="tfd-izq">
                        @if ($qr['imagen'])
                            <img src="{{ $qr['imagen'] }}" width="115">
                        @elseif ($qr['url'])
                            <p class="tfd-label tfd-label-primera">Verificación</p>
                            <x-pdf.mono-box :texto="$qr['url']" />
                        @endif

                        <p class="tfd-label">Folio fiscal (UUID)</p>
                        <p class="mono-sm">{{ $factura->uuid_fiscal }}</p>

                        <p class="tfd-label">Serie del CSD del SAT</p>
                        <p class="mono-sm">{{ $factura->no_certificado_sat }}</p>
                    </td>

                    <td class="tfd-der">
                        <p class="tfd-label tfd-label-primera">Sello CFDI</p>
                        <x-pdf.mono-box :texto="$factura->sello_cfdi" />

                        <p class="tfd-label">Sello SAT</p>
                        <x-pdf.mono-box :texto="$factura->sello_sat" />

                        <p class="tfd-label">Cadena original del complemento de certificación digital del SAT</p>
                        <x-pdf.mono-box :texto="$factura->cadena_original_sat" />
                    </td>
                </tr>
            </table>
        </div>
    @endif
@endsection
