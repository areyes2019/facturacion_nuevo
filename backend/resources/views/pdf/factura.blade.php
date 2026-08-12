<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura {{ $factura->folio }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'Helvetica', 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-family: 'Helvetica', 'DejaVu Sans', sans-serif; font-size: 18px; color: #4F46E5; margin: 0 0 4px; }
        .subtitulo { color: #64748b; margin: 0 0 16px; }
        table { width: 100%; border-collapse: collapse; }
        .encabezado { width: 100%; margin-bottom: 16px; }
        .encabezado td { vertical-align: top; }
        .caja { border: 1px solid #e2e8f0; border-radius: 4px; padding: 8px 10px; margin-bottom: 10px; }
        .caja h2 { font-size: 11px; text-transform: uppercase; color: #7C3AED; margin: 0 0 6px; }
        .lineas th { background: #4F46E5; color: #fff; padding: 6px 8px; text-align: left; font-size: 10px; }
        .lineas td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 10px; }
        .num { text-align: right; }
        .totales { width: 260px; margin-left: auto; margin-top: 10px; }
        .totales td { padding: 3px 6px; font-size: 11px; }
        .totales .total { font-weight: bold; font-size: 13px; border-top: 1px solid #4F46E5; }
        .sellos { margin-top: 16px; font-size: 8px; color: #64748b; word-wrap: break-word; }
        .sellos p { margin: 2px 0; word-break: break-all; }
        .estado-cancelada { color: #DC2626; font-weight: bold; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            <td>
                <h1>Facturación</h1>
                <p class="subtitulo">Representación impresa de CFDI</p>
            </td>
            <td class="num">
                <p><strong>Folio:</strong> {{ $factura->facturapi_serie }}{{ $factura->facturapi_folio ?? $factura->folio }}</p>
                <p><strong>Fecha de timbrado:</strong> {{ optional($factura->fecha_timbrado)->format('d/m/Y H:i') }}</p>
                @if ($factura->estado->value === 'cancelada')
                    <p class="estado-cancelada">CANCELADA</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="caja">
        <h2>Receptor</h2>
        <p><strong>{{ $factura->cliente->razon_social }}</strong> ({{ $factura->cliente->rfc }})</p>
        <p>Régimen fiscal: {{ $factura->cliente->regimen_fiscal }} &middot; CP: {{ $factura->cliente->codigo_postal_fiscal }}</p>
    </div>

    <div class="caja">
        <h2>Datos del comprobante</h2>
        <p>Uso de CFDI: {{ $factura->uso_cfdi }} &middot; Forma de pago: {{ $factura->forma_pago }} &middot; Método de pago: {{ $factura->metodo_pago->value }}</p>
    </div>

    <table class="lineas">
        <thead>
            <tr>
                <th>Cant.</th>
                <th>Descripción</th>
                <th>Modelo</th>
                <th class="num">Precio unitario</th>
                <th class="num">Descuento</th>
                <th>IVA</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($factura->lineas as $linea)
                <tr>
                    <td>{{ $linea->cantidad }}</td>
                    <td>{{ $linea->descripcion }}</td>
                    <td>{{ $linea->modelo }}</td>
                    <td class="num">${{ number_format($linea->precio_unitario, 2) }}</td>
                    <td class="num">
                        @if ($linea->descuento_valor)
                            {{ $linea->descuento_tipo->value === 'porcentaje' ? $linea->descuento_valor.'%' : '$'.number_format($linea->descuento_valor, 2) }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $linea->tasa_iva->value === 'exento' ? 'Exento' : $linea->tasa_iva->value.'%' }}</td>
                    <td class="num">${{ number_format($linea->importe, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr><td>Subtotal</td><td class="num">${{ number_format($factura->subtotal, 2) }}</td></tr>
        <tr><td>Descuento</td><td class="num">${{ number_format($factura->total_descuento, 2) }}</td></tr>
        <tr><td>IVA 16%</td><td class="num">${{ number_format($factura->total_iva_16, 2) }}</td></tr>
        <tr><td>IVA 0% / Exento</td><td class="num">${{ number_format($factura->total_iva_0 + $factura->total_exento, 2) }}</td></tr>
        <tr class="total"><td>Total</td><td class="num">${{ number_format($factura->total, 2) }} {{ $factura->moneda }}</td></tr>
    </table>

    <div class="sellos">
        <p><strong>Folio fiscal (UUID):</strong> {{ $factura->uuid_fiscal }}</p>
        <p><strong>No. de certificado del SAT:</strong> {{ $factura->no_certificado_sat }}</p>
        <p><strong>Sello CFDI:</strong> {{ $factura->sello_cfdi }}</p>
        <p><strong>Sello SAT:</strong> {{ $factura->sello_sat }}</p>
        <p><strong>Cadena original del complemento de certificación digital del SAT:</strong>
            {{ $factura->cadena_original_sat }}</p>
    </div>
</body>
</html>
