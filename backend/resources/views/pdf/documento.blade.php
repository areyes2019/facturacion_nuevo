{{--
    Plantilla base de los tres documentos impresos (ver 019-formato-pdf-documentos.md).

    Aquí vive TODO lo común: la apariencia, el encabezado con logos, el bloque del emisor, la tabla
    de conceptos, el bloque de totales y el pie. Cotización, factura y orden de compra solo declaran
    lo que cambia, y por eso un ajuste visual se hace una vez y los tres cambian juntos.

    Recibe por `@extends`: $titulo, $folio, $fecha, $estadoEtiqueta, $estadoCancelado, $lineas,
    $documento, $etiquetaPrecio, $moneda y $notaPie. El $emisor y el resolutor $sat los inyecta
    EmisorComposer, para que también lleguen en las rutas públicas firmadas, que no tienen usuario.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }} {{ $folio }}</title>
    <style>
        /*
         * DejaVu Sans y no Helvetica: Helvetica es una fuente "de fábrica" del formato PDF, sin
         * archivo propio y con repertorio limitado, y los acentos y la eñe salen mal. DejaVu viene
         * incluida con dompdf, no hay que instalarla, y trae el repertorio completo.
         */
        @page { margin: 28px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #333; margin: 0; padding: 0; }
        p { margin: 0; }

        td { word-wrap: break-word; overflow-wrap: break-word; }

        .encabezado { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .encabezado td { vertical-align: top; }
        .logo { max-width: 200px; max-height: 70px; }
        .logo-marca { max-width: 150px; max-height: 70px; }
        .doc-titulo { font-size: 18pt; font-weight: bold; color: #2c3e50; }
        .doc-folio { font-size: 13pt; }
        .derecha { text-align: right; }

        .partes { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 9pt; }
        .partes td { vertical-align: top; }
        .parte-titulo { font-weight: bold; color: #2c3e50; border-bottom: 1px solid #2c3e50; padding-bottom: 3px; margin-bottom: 5px; }
        .parte-izq { width: 48%; padding-right: 10px; }
        .parte-sep { width: 4%; }
        .parte-der { width: 48%; padding-left: 10px; border-left: 1px solid #ddd; }
        .vigente { color: #27ae60; font-weight: bold; }
        .cancelado { color: #c0392b; font-weight: bold; }

        .items { width: 100%; border-collapse: collapse; font-size: 11px; }
        .items td, .items th { border: 1px solid #95a5a6; padding: 6px; }
        .items thead { background: #f5f5f5; font-weight: bold; }
        .items th { text-align: left; }
        .num { text-align: right; }

        .totales { width: 32%; float: right; border-collapse: collapse; margin-top: 10px; }
        .totales td { border: 1px solid #95a5a6; padding: 7px; }
        .totales .total { background: #f5f5f5; font-weight: bold; }
        .limpiar { clear: both; margin-bottom: 15px; }

        .caja { border: 1px solid #e2e8f0; padding: 8px 10px; margin-top: 14px; font-size: 9pt; }
        .caja h2 { font-size: 10px; text-transform: uppercase; color: #2c3e50; margin: 0 0 6px; }

        .timbre { margin-top: 30px; border-top: 2px solid #2c3e50; padding-top: 10px; font-size: 8pt; }
        .timbre-titulo { font-weight: bold; font-size: 8.5pt; text-align: center; margin-bottom: 8px; letter-spacing: 1px; text-transform: uppercase; color: #2c3e50; }
        .tfd { width: 100%; border-collapse: collapse; }
        .tfd-izq { width: 23%; vertical-align: top; padding-right: 8px; border-right: 1px solid #ccc; }
        .tfd-der { width: 77%; vertical-align: top; padding-left: 10px; max-width: 77%; overflow: hidden; }
        .tfd-label { font-weight: bold; font-size: 7pt; color: #555; margin-bottom: 1px; margin-top: 5px; }
        .tfd-label-primera { margin-top: 0; }
        .mono-sm { font-family: 'DejaVu Sans Mono', monospace; font-size: 6.5pt; word-wrap: break-word; white-space: normal; }

        .nota { text-align: center; margin-top: 8px; font-size: 7.5pt; color: #666; font-style: italic; border-top: 1px solid #eee; padding-top: 5px; }
    </style>
</head>
<body>

{{-- ENCABEZADO: logos a la izquierda, nombre del documento y folio a la derecha --}}
<table class="encabezado">
    <tr>
        <td width="30%">
            @php($logo = $emisor->logoBase64('principal'))
            @if ($logo)
                <img src="{{ $logo }}" class="logo">
            @endif
        </td>
        <td width="30%">
            @php($logoMarca = $emisor->logoBase64('marca'))
            @if ($logoMarca)
                <img src="{{ $logoMarca }}" class="logo-marca">
            @endif
        </td>
        <td width="40%" class="derecha">
            <p class="doc-titulo">{{ $titulo }}</p>
            <p class="doc-folio">{{ $folio }}</p>
        </td>
    </tr>
</table>

{{-- EMISOR / CONTRAPARTE --}}
<table class="partes">
    <tr>
        <td class="parte-izq">
            <p class="parte-titulo">Emisor</p>
            @if ($emisor->estaCompleto())
                <p><strong>{{ $emisor->nombre }}</strong></p>
                <p>RFC: <strong>{{ $emisor->rfc }}</strong></p>
                @if ($emisor->domicilio)
                    <p>{{ $emisor->domicilio }}</p>
                @endif
                <p>Régimen: {{ $sat->regimenFiscal($emisor->regimen_fiscal) }}</p>
                @if ($emisor->correo)
                    <p>Correo: {{ $emisor->correo }}</p>
                @endif
                @if ($emisor->telefono)
                    <p>Teléfono: {{ $emisor->telefono }}</p>
                @endif
            @else
                {{-- Un documento nunca se bloquea por configuración incompleta; el sistema avisa
                     por otro lado (ver "Qué pasa si el emisor está vacío" en la 019). --}}
                <p>Sin datos fiscales capturados</p>
            @endif

            <p style="margin-top:6px">Fecha: {{ $fecha->format('d/m/Y') }}</p>
            <p>Estado: <span class="{{ $estadoCancelado ? 'cancelado' : 'vigente' }}">{{ $estadoEtiqueta }}</span></p>
            @yield('meta-extra')
        </td>

        <td class="parte-sep"></td>

        <td class="parte-der">
            @yield('contraparte')
        </td>
    </tr>
</table>

{{-- CONCEPTOS --}}
<table class="items">
    <thead>
        <tr>
            <th width="7%">Cant.</th>
            <th width="9%">Clave SAT</th>
            <th width="33%">Descripción</th>
            <th width="13%">Modelo</th>
            <th width="12%" class="num">{{ $etiquetaPrecio }}</th>
            <th width="10%" class="num">Desc.</th>
            <th width="6%">IVA</th>
            <th width="10%" class="num">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lineas as $linea)
            <tr>
                <td>{{ $linea->cantidad }}</td>
                {{-- La línea no guarda copia de la clave: sale del artículo, cargado con
                     withTrashed para que una reimpresión no la pierda al darlo de baja. Una línea
                     de texto libre (sin artículo) deja la celda vacía. --}}
                <td>{{ $linea->articulo?->clave_prod_serv }}</td>
                <td>{{ $linea->descripcion }}</td>
                <td>{{ $linea->modelo }}</td>
                <td class="num">${{ number_format($linea->precio_unitario, 2) }}</td>
                <td class="num">
                    @if ($linea->descuento_valor)
                        {{ $linea->descuento_tipo->value === 'porcentaje' ? $linea->descuento_valor.'%' : '$'.number_format($linea->descuento_valor, 2) }}
                    @else
                        &mdash;
                    @endif
                </td>
                <td>{{ $linea->tasa_iva->value === 'exento' ? 'Exento' : $linea->tasa_iva->value.'%' }}</td>
                <td class="num">${{ number_format($linea->importe, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- TOTALES. Los renglones en cero no se imprimen: una cotización sin nada exento no gana nada
     mostrando un $0.00. --}}
<table class="totales">
    <tr>
        <td><strong>Subtotal</strong></td>
        <td class="num">${{ number_format($documento->subtotal, 2) }}</td>
    </tr>
    @if ((float) $documento->total_descuento > 0)
        <tr>
            <td><strong>Descuento</strong></td>
            <td class="num">-${{ number_format($documento->total_descuento, 2) }}</td>
        </tr>
    @endif
    @if ((float) $documento->total_iva_16 > 0)
        <tr>
            <td><strong>IVA 16%</strong></td>
            <td class="num">${{ number_format($documento->total_iva_16, 2) }}</td>
        </tr>
    @endif
    @if ((float) $documento->total_iva_0 + (float) $documento->total_exento > 0)
        <tr>
            <td><strong>IVA 0% / Exento</strong></td>
            <td class="num">${{ number_format($documento->total_iva_0 + $documento->total_exento, 2) }}</td>
        </tr>
    @endif
    <tr class="total">
        <td><strong>Total</strong></td>
        <td class="num"><strong>${{ number_format($documento->total, 2) }} {{ $moneda }}</strong></td>
    </tr>
</table>
<div class="limpiar"></div>

@yield('extras')

@yield('timbre')

<p class="nota">{{ $notaPie }}</p>

</body>
</html>
