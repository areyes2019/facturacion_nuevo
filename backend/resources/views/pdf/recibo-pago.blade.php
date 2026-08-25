{{--
    Recibo de un pago de cotización (ver 040-recibo-anticipo-cotizacion.md).

    No extiende `pdf.documento`, mismo motivo que `pdf.lista-precios` (028): esa plantilla base
    asume una tabla de líneas con cantidad/descuento/IVA que un recibo de pago no tiene. Sí
    reutiliza su paleta y su tipografía, para que se sienta de la misma familia que cotizaciones y
    facturas.

    $emisor llega gratis: `View::composer('pdf.*', EmisorComposer::class)` (ver
    AppServiceProvider) ya cubre todo el namespace `pdf.*`, no solo `pdf.documento`.

    Recibe por `loadView`: $cotizacion (con `cliente` cargado), $pago (con `cuenta` cargada) y
    $saldoPendienteTrasPago (el saldo justo después de este pago, ya resuelto por el controlador vía
    `CotizacionPago::saldoPendienteTrasEste()`).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de pago</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #333; margin: 0; padding: 0; }
        p { margin: 0; }

        .encabezado { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .encabezado td { vertical-align: top; }
        /* En milímetros, no en píxeles: misma caja que la plantilla base de los demás documentos. */
        .logo { max-width: 55mm; max-height: 40mm; }
        .doc-titulo { font-size: 18pt; font-weight: bold; color: #2c3e50; }
        .doc-subtitulo { font-size: 13pt; }
        .doc-fecha { font-size: 9pt; color: #666; }
        .derecha { text-align: right; }

        .parte-titulo {
            font-weight: bold;
            color: #2c3e50;
            border-bottom: 1px solid #2c3e50;
            padding-bottom: 3px;
            margin: 16px 0 6px;
            font-size: 11pt;
        }

        .datos { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px; }
        .datos td { border: 1px solid #95a5a6; padding: 6px; }
        .datos .etiqueta { background: #f5f5f5; font-weight: bold; width: 35%; }

        .monto { text-align: center; margin: 20px 0; }
        .monto-cifra { font-size: 26pt; font-weight: bold; color: #2c3e50; }
        .monto-etiqueta { font-size: 9pt; color: #666; }

        .nota { text-align: center; margin-top: 14px; font-size: 7.5pt; color: #666; font-style: italic; border-top: 1px solid #eee; padding-top: 5px; }
    </style>
</head>
<body>

@php
    $etiquetasTipo = [
        'anticipo' => 'Anticipo',
        'saldo' => 'Saldo',
        'pago_total' => 'Pago total',
    ];
    $etiquetaTipo = $etiquetasTipo[$pago->tipo->value];
@endphp

<table class="encabezado">
    <tr>
        <td width="30%">
            @php($logo = $emisor->logoBase64('principal'))
            @if ($logo)
                <img src="{{ $logo }}" class="logo">
            @endif
        </td>
        <td width="70%" class="derecha">
            <p class="doc-titulo">Recibo de pago</p>
            <p class="doc-subtitulo">{{ $etiquetaTipo }}</p>
            <p class="doc-fecha">Generado el {{ now()->format('d/m/Y') }}</p>
        </td>
    </tr>
</table>

<p class="parte-titulo">Cotización</p>
<table class="datos">
    <tr>
        <td class="etiqueta">Folio</td>
        <td>COT-{{ str_pad((string) $cotizacion->folio, 5, '0', STR_PAD_LEFT) }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Cliente</td>
        <td>{{ $cotizacion->cliente->razon_social }}</td>
    </tr>
    <tr>
        <td class="etiqueta">RFC</td>
        <td>{{ $cotizacion->cliente->rfc }}</td>
    </tr>
</table>

<p class="parte-titulo">Pago</p>
<table class="datos">
    <tr>
        <td class="etiqueta">Fecha de pago</td>
        <td>{{ $pago->fecha_pago->format('d/m/Y') }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Forma de pago</td>
        <td>{{ $pago->cuenta->nombre }}</td>
    </tr>
    <tr>
        <td class="etiqueta">Saldo pendiente tras este pago</td>
        <td>${{ number_format($saldoPendienteTrasPago, 2) }}</td>
    </tr>
</table>

<div class="monto">
    <p class="monto-cifra">${{ number_format($pago->monto, 2) }}</p>
    <p class="monto-etiqueta">Monto pagado ({{ $etiquetaTipo }})</p>
</div>

<p class="nota">Este documento es un comprobante interno de pago, no un CFDI.</p>

</body>
</html>
