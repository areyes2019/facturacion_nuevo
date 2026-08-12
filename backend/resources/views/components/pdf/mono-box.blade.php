@props(['texto'])

{{--
    Caja monoespaciada para los sellos y la cadena original (ver 019-formato-pdf-documentos.md).

    Estas tiras miden de 350 a 500 caracteres SIN UN SOLO ESPACIO. Un maquetador corta los renglones
    en los espacios: sin ellos concluye que es una sola palabra, la escribe de corrido y lo que se
    sale de la caja no se imprime, sin error ni aviso.

    La solución son dos cosas a la vez, y ninguna basta sola:

      1. `chunk_split` mete un salto de línea cada 120 caracteres. En HTML ese salto no dibuja nada
         —se colapsa como espacio en blanco—; lo que hace es CREAR UNA OPORTUNIDAD DE CORTE donde no
         había ninguna.
      2. `word-wrap: break-word` parte por ancho si algún fragmento todavía no cabe.

    Se corta ANTES de escapar: al revés, un salto puede caer dentro de una entidad HTML (`&amp;`
    partido en `&am` + `p;`) y esa entidad se imprimiría literal. Con los sellos no pasaría —son
    base64— pero la cadena original sí lleva texto y sí puede llevar `&`. Blade escapa el resultado
    de `chunk_split`, que es el orden correcto.

    ⚠️ LOS 120 CARACTERES Y EL 5.8pt VAN AMARRADOS: a ese tamaño de letra, 120 caracteres llenan
    justo el ancho de la caja. Quien agrande la letra sin bajar el número volverá a tener texto
    fuera de la hoja. Por eso los dos valores se declaran aquí, juntos, y no repartidos entre esta
    plantilla y la hoja de estilos.
--}}
<div style="font-family: 'DejaVu Sans Mono', monospace; font-size: 5.8pt; line-height: 1.25;
            border: 1px solid #ddd; background: #fafafa; padding: 4px; margin: 2px 0 5px;
            width: 100%; max-width: 100%; display: block;
            white-space: normal; word-wrap: break-word; overflow-wrap: break-word;
            page-break-inside: avoid;">{{ chunk_split((string) $texto, 120, "\n") }}</div>
