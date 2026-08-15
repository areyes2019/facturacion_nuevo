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

{{-- Datos bancarios para que el cliente pague (ver 026-datos-bancarios-cotizacion.md).

     Se imprime la copia congelada de la propia cotización, no la lista vigente: este PDF se
     regenera cada vez que se abre o se reenvía, y el cliente ya tiene el suyo. Una cotización sin
     bancos —o anterior a esta historia— no imprime nada y el encabezado queda como estaba. --}}
@section('encabezado-extra')
    @if (filled($cotizacion->datos_bancarios))
        <div class="bancos">
            <p class="bancos-titulo">Datos bancarios</p>
            @foreach ($cotizacion->datosBancariosParaPdf() as $banco)
                <div class="banco">
                    {{-- El nombre va en una tabla de dos celdas y no en un párrafo con la imagen en
                         línea: el bloque está alineado a la derecha y dompdf no coloca de forma
                         fiable un <img> dentro de un párrafo así. Un banco sin icono no imprime la
                         celda, para que el nombre no quede sangrado respecto de los demás. --}}
                    @if ($banco['logo_base64'])
                        <table class="banco-encabezado">
                            <tr>
                                <td class="banco-icono"><img src="{{ $banco['logo_base64'] }}" class="icono"></td>
                                <td class="banco-nombre">{{ $banco['nombre_banco'] }}</td>
                            </tr>
                        </table>
                    @else
                        <p class="banco-nombre">{{ $banco['nombre_banco'] }}</p>
                    @endif
                    {{-- Un campo vacío no imprime su renglón: un banco al que solo se le capturó
                         CLABE ocupa dos renglones, no cinco con tres guiones. --}}
                    @if (filled($banco['beneficiario'] ?? null))
                        <p>{{ $banco['beneficiario'] }}</p>
                    @endif
                    @if (filled($banco['numero_cuenta'] ?? null))
                        <p>Cta: {{ $banco['numero_cuenta'] }}</p>
                    @endif
                    @if (filled($banco['tarjeta'] ?? null))
                        <p>Tarjeta: {{ $banco['tarjeta'] }}</p>
                    @endif
                    @if (filled($banco['clabe'] ?? null))
                        <p>CLABE: {{ $banco['clabe'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endsection

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
