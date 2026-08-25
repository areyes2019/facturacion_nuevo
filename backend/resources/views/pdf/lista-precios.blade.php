{{--
    Lista de precios en PDF (ver 028-lista-precios-pdf.md).

    No extiende `pdf.documento`: esa plantilla base asume una tabla de conceptos con cantidad,
    descuento e IVA por línea y un bloque de totales que esta lista no tiene, y forzar esos campos
    con valores inventados solo para que la vista no truene sería peor que tener una vista propia.
    Sí se reutiliza su paleta y su tipografía, para que el documento se sienta de la misma familia
    que cotizaciones y facturas.

    $emisor y $sat llegan gratis: `View::composer('pdf.*', EmisorComposer::class)` (ver
    AppServiceProvider) ya cubre todo el namespace `pdf.*`, no solo `pdf.documento`.

    Recibe por `loadView`: $secciones (una colección de artículos agrupada por nombre de catálogo,
    ya ordenada, cada uno con su `precio_lista` ya resuelto por el controlador según el tipo
    pedido), $mostrarSecciones (si hay más de un catálogo en la selección), $tituloColumnaPrecio
    ("Precio Distribuidor" o "Precio Público") y $fecha.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Lista de precios</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #333; margin: 0; padding: 0; }
        p { margin: 0; }

        .encabezado { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        .encabezado td { vertical-align: top; }
        /* En milímetros, no en píxeles: misma caja que la plantilla base de los demás documentos. */
        .logo, .logo-marca { max-width: 55mm; max-height: 40mm; }
        .doc-titulo { font-size: 18pt; font-weight: bold; color: #2c3e50; }
        .doc-fecha { font-size: 13pt; }
        .derecha { text-align: right; }

        .seccion-titulo {
            font-weight: bold;
            color: #2c3e50;
            border-bottom: 1px solid #2c3e50;
            padding-bottom: 3px;
            margin: 16px 0 6px;
            font-size: 11pt;
        }

        .items { width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 6px; }
        .items td, .items th { border: 1px solid #95a5a6; padding: 6px; }
        .items thead { background: #f5f5f5; font-weight: bold; }
        .items th { text-align: left; }
        .num { text-align: right; }
        /* En milímetros, no en píxeles: mismo criterio que los logos del encabezado. Es una caja
           chica a propósito (ver 028-lista-precios-pdf.md): la miniatura solo ayuda a reconocer el
           artículo de un vistazo, no reemplaza a la ficha con la foto completa. */
        .miniatura-celda { text-align: center; padding: 3px; }
        .miniatura { max-width: 15mm; max-height: 15mm; }

        .nota { text-align: center; margin-top: 14px; font-size: 7.5pt; color: #666; font-style: italic; border-top: 1px solid #eee; padding-top: 5px; }
    </style>
</head>
<body>

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
            <p class="doc-titulo">Lista de precios</p>
            <p class="doc-fecha">{{ $fecha->format('d/m/Y') }}</p>
        </td>
    </tr>
</table>

@foreach ($secciones as $catalogoNombre => $articulos)
    @if ($mostrarSecciones)
        <p class="seccion-titulo">{{ $catalogoNombre }}</p>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th width="15%"></th>
                <th width="42%">Nombre</th>
                <th width="23%">Modelo</th>
                <th width="20%" class="num">{{ $tituloColumnaPrecio }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($articulos as $articulo)
                <tr>
                    <td class="miniatura-celda">
                        @if ($articulo->miniatura_base64)
                            <img src="{{ $articulo->miniatura_base64 }}" class="miniatura">
                        @endif
                    </td>
                    <td>{{ $articulo->nombre }}</td>
                    <td>{{ $articulo->modelo }}</td>
                    <td class="num">${{ number_format($articulo->precio_lista, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endforeach

<p class="nota">Precios sujetos a cambio sin previo aviso — vigentes al {{ $fecha->format('d/m/Y') }}.</p>

</body>
</html>
