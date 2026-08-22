# Spec: Lista de precios en PDF desde el listado de artículos

## Historia de usuario

Como usuario, quiero marcar un grupo de artículos en `/articulos` —filtrando por catálogo y
usando "seleccionar todos", o marcándolos uno por uno— y generar con un clic una lista de precios
en PDF con la miniatura de su foto (si la tiene), su nombre, su modelo y su precio distribuidor,
para compartirla de inmediato por el menú nativo de Windows 11 con un cliente o un distribuidor,
sin editar cada artículo ni armar el documento a mano. La miniatura ayuda a reconocer cada
artículo de un vistazo, sin depender de que el modelo por sí solo le diga algo a quien recibe la
lista.

## Objetivo / Alcance

Un botón nuevo, **"Compartir Lista"**, en la misma barra de selección que ya usan "Eliminar"
([021](021-mantenimiento-articulos-catalogos.md)) y "Mover a catálogo"
([034](034-filtro-catalogo-y-mover-lote-articulos.md)): con uno o más artículos marcados, genera un
PDF con la miniatura de la foto (si el artículo tiene una, ver
[020](020-imagenes-articulos.md)), el nombre, el modelo y el precio distribuidor (con IVA incluido)
de cada artículo seleccionado, y lo entrega al menú de compartir del sistema operativo mediante el
mecanismo que [`lib/compartir.ts`](../frontend/src/lib/compartir.ts) ya resuelve para cotizaciones,
facturas y tickets.

No se crea ningún mecanismo nuevo de selección: se reutilizan los checkboxes de la tabla, el
filtro de catálogo de 034 y `compartirArchivo()` tal como están. Tampoco se crea un documento
persistente: la lista se genera al vuelo, no se guarda en base de datos ni se asocia a ningún
cliente.

## Backend (Laravel)

### Endpoint

| Método | Ruta | Qué hace |
| --- | --- | --- |
| `POST` | `/api/v1/articulos/lista-precios` | `{ "ids": [1, 2, 3] }` → PDF (`application/pdf`) |

Se registra junto a `eliminar-lote` y `mover-lote`, **antes** del `apiResource('articulos')`,
siguiendo la misma convención de rutas específicas primero (`routes/api.php:79-83`).

### `ListaPreciosArticulosRequest`

Mismas reglas que `EliminarLoteArticulosRequest` (`app/Http/Requests/Articulos/EliminarLoteArticulosRequest.php`):
`ids` requerido, array, mínimo 1; cada uno entero y existente en `articulos`, restringido al
`user_id` autenticado y no borrado. Un identificador ajeno o inexistente rechaza la petición
completa con `422` — no tiene sentido generar "lo que sí existe" en un documento que el usuario va
a compartir tal cual sale.

No hay un tope máximo de `ids` en el servidor: el aviso por selección grande (ver "Frontend") es
solo del navegador, para no tener que sincronizar dos números si algún día cambia.

### `ArticuloController::listaPrecios`

```php
public function listaPrecios(ListaPreciosArticulosRequest $request, ImagenArticuloService $imagenes): Response
{
    $ids = $request->validated()['ids'];

    $articulos = $request->user()->articulos()
        ->whereIn('id', $ids)
        ->with('catalogo')
        ->get()
        ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE);

    // Miniatura como atributo transitorio (no persistido): la vista la lee como cualquier otro
    // campo del artículo, sin que el controlador pase un mapa aparte ni la vista sepa nada de
    // ImagenArticuloService.
    foreach ($articulos as $articulo) {
        $articulo->miniatura_base64 = $imagenes->miniaturaBase64($articulo, self::LADO_MINIATURA_LISTA_PRECIOS);
    }

    $secciones = $articulos
        ->groupBy(fn (Articulo $articulo) => $articulo->catalogo?->nombre ?? 'Sin catálogo')
        ->sortKeys();

    $pdf = app('dompdf.wrapper')->loadView('pdf.lista-precios', [
        'secciones' => $secciones,
        'mostrarSecciones' => $secciones->count() > 1,
        'fecha' => now(),
    ]);

    return $pdf->stream('lista-precios-'.now()->format('Y-m-d').'.pdf');
}
```

- **Orden alfabético** (adición técnica 1): se ordena antes de agrupar, y `groupBy` conserva el
  orden relativo dentro de cada grupo, así que no hace falta ordenar dos veces.
- **Agrupar por catálogo solo si hay más de uno** (adición técnica 2): `mostrarSecciones` es
  `false` cuando todos los artículos seleccionados son del mismo catálogo, y la vista no imprime
  ningún subtítulo — mezclar todo bajo un único encabezado de sección sería ruido cuando ya se
  sabe de qué catálogo se trata.
- El precio impreso es `$articulo->precio_distribuidor_con_iva` (`Articulo.php:148-152`), el mismo
  accessor que ya usa la ficha compartida del artículo (`ArticuloDetalleDialog.vue`) y que aplica
  el factor de IVA correcto según `objeto_imp` — un artículo que no causa IVA sale con su factor en
  1.0, igual que en cualquier otra pantalla del sistema.
- Un artículo sin precio distribuidor configurado (`precio_distribuidor_sin_iva` en `0` o `null`)
  se imprime igual, con `$0.00`: no se excluye ni se oculta, para que la ausencia de precio sea
  visible y no un artículo que simplemente falta.
- No se persiste nada: no hay modelo, migración ni tabla nueva para esta lista. Es un documento
  efímero, igual de espíritu a como el ticket de mostrador se genera al vuelo
  (`TicketPedidoService`).

### La miniatura de la imagen

`ImagenArticuloService` (ver [020](020-imagenes-articulos.md)) gana un método nuevo,
`miniaturaBase64(Articulo $articulo, int $ladoMaximo): ?string`, hermano de `contenido()` que ya
tenía:

```php
public function miniaturaBase64(Articulo $articulo, int $ladoMaximo): ?string
{
    $ruta = $this->rutaSiExiste($articulo);

    if ($ruta === null) {
        return null;
    }

    try {
        $contenido = $this->procesador->procesar(Storage::disk('local')->path($ruta), $ladoMaximo);
    } catch (Throwable) {
        return null;
    }

    return 'data:image/webp;base64,'.base64_encode($contenido);
}
```

- **Es un segundo tamaño, no la foto completa.** La imagen guardada del artículo ya está reducida a
  `ImagenArticuloService::LADO_MAXIMO` (1200 puntos de lado largo), pensada para una ficha con una
  sola foto grande. Incrustar esa versión en una tabla que puede llevar decenas de artículos
  multiplicaría el peso del PDF y el tiempo de generación por cada fila. Se reprocesa con
  `ProcesadorImagen` —la misma pieza que ya usan `ImagenArticuloService` y `LogoBancoService`, cada
  una con su propio tamaño (ver [026](026-datos-bancarios-cotizacion.md))— a un lado máximo de
  **120 puntos**, con la constante `ArticuloController::LADO_MINIATURA_LISTA_PRECIOS`.
- **Se lee del disco por ruta, no por los bytes de `contenido()`.** `ProcesadorImagen::procesar()`
  necesita una ruta real de archivo (usa `getimagesize()` e `imagecreatefromwebp()`), así que se
  resuelve con `Storage::disk('local')->path($ruta)` en vez de pasar por `Storage::get()` y guardar
  un archivo temporal.
- **Sin imagen, sin archivo en disco o con un archivo dañado, devuelve `null` en silencio, sin
  `Log::warning`.** A diferencia del logo del emisor —que si falta se nota en cada documento fiscal
  y por eso 019 sí lo registra—, la miniatura de un artículo es puramente decorativa: perderla no
  cambia ningún dato del PDF, y un artículo de cada varios sin foto es el caso normal, no una falla
  que valga la pena anotar.
- Se calcula en el controlador y se cuelga como atributo transitorio del modelo
  (`$articulo->miniatura_base64 = ...`), sin persistirse: la vista lo lee igual que cualquier otro
  campo del artículo.

### Vista `resources/views/pdf/lista-precios.blade.php`

**No extiende `pdf.documento`.** Esa plantilla base asume una tabla de conceptos con cantidad,
descuento e IVA por línea y un bloque de totales (`documento.blade.php:163-239`) que una lista de
precios no tiene — forzar esos campos con valores inventados solo para que la vista no truene
sería peor que tener una plantilla propia. Lo que sí se reutiliza es la paleta y la tipografía: el
gris azulado `#2c3e50` para títulos, `#95a5a6` para bordes de tabla, `#f5f5f5` para el fondo de
cabecera, y DejaVu Sans, para que el documento se sienta de la misma familia que cotizaciones y
facturas sin ser una extensión forzada de esa plantilla.

Al vivir bajo `resources/views/pdf/`, la vista recibe `$emisor` y `$sat` gratis:
`View::composer('pdf.*', EmisorComposer::class)` (`AppServiceProvider.php:44`) ya se registró
sobre todo el namespace `pdf.*`, no solo sobre `pdf.documento` — no hace falta tocar el composer.

Estructura:

- **Encabezado**: los dos logos del emisor a la izquierda (`$emisor->logoBase64('principal')` /
  `'marca'`), misma caja de 55×40 mm que la plantilla base; a la derecha, "Lista de precios" en
  18pt sobre `#2c3e50` y debajo, en 13pt, la fecha de generación (adición técnica 5) en formato
  `d/m/Y`. Sin logos cargados, el encabezado no se descuadra, igual que en los otros tres
  documentos.
- **Por cada sección** (si `$mostrarSecciones`): un subtítulo con el nombre del catálogo, en el
  mismo estilo `.parte-titulo` de la plantilla base.
- **Tabla por sección**: columnas Miniatura, Nombre, Modelo, Precio — con el mismo estilo de bordes
  y cabecera que `.items` en `documento.blade.php`. Sin columnas de cantidad, descuento ni IVA:
  esta lista no es una cotización. La columna de miniatura es la primera, a la izquierda, sin texto
  en su cabecera (una celda vacía, igual que la columna de la casilla de selección en la tabla de
  `/articulos`); su celda de datos queda vacía cuando el artículo no tiene `miniatura_base64`, sin
  ningún ícono de "sin foto" que la reemplace. La imagen se limita a 15×15 mm —en milímetros, no en
  píxeles, mismo criterio que los logos del encabezado— para que quepa cómoda en el ancho de la
  columna sin desbordar la fila.
- **Pie**: *"Precios sujetos a cambio sin previo aviso — vigentes al {fecha en d/m/Y}."* (adición
  técnica 3), en el mismo estilo `.nota` que el resto de los documentos.

### Tests

Feature tests sobre la base MySQL de trabajo con `php artisan test`, nunca `migrate:fresh`
([[feedback_nunca_migrate_fresh_en_dev]]):

1. `POST /articulos/lista-precios` con artículos de un solo catálogo devuelve un PDF sin secciones
   visibles (una sola, sin subtítulo).
2. Con artículos de dos catálogos distintos, el PDF agrupa cada uno bajo su propio subtítulo.
3. Los artículos salen ordenados alfabéticamente por nombre dentro de cada sección.
4. El precio impreso es el precio distribuidor con IVA incluido, no el directo ni el sin IVA.
5. Un artículo con `precio_distribuidor_sin_iva` en `0` se incluye en el PDF con `$0.00`.
6. Un artículo que no causa IVA (`objeto_imp` sin traslado) imprime su precio distribuidor sin el
   16% adicional.
7. Un `id` ajeno o inexistente en la lista responde `422` y no genera ningún PDF.
8. El PDF se genera igual sin logos del emisor cargados y con el emisor vacío, sin lanzar
   excepción — mismo criterio de "nunca se bloquea" que 019.
9. Un artículo con imagen imprime su miniatura (una data URI `data:image/webp;base64,...`) en la
   primera columna de su fila.
10. Un artículo sin imagen no imprime ninguna miniatura, sin romper el PDF.
11. `ImagenArticuloService::miniaturaBase64()` reduce la imagen ya guardada a un lado máximo menor
    (probado con 120), sin importar el tamaño de la imagen original.
12. `miniaturaBase64()` devuelve `null` tanto si el artículo no tiene imagen como si el archivo
    referenciado por `imagen_ruta` ya no está en disco, sin lanzar ninguna excepción.

## Frontend (Vue 3)

La miniatura no toca el frontend: es enteramente responsabilidad del PDF generado en el servidor,
a partir de una imagen que ya existe (o no) por el flujo normal de carga de fotos de
[020](020-imagenes-articulos.md). No hay ningún control nuevo para subir, cambiar o desactivar la
miniatura desde `/articulos`.

### `stores/articulos.ts`

Nueva acción, junto a `removeLote` y `moverLoteCatalogo`:

```ts
/** El PDF de la lista de precios, dibujado por el servidor, como Blob listo para compartir. */
async listaPreciosBlob(ids: number[]): Promise<Blob> {
  const { data } = await http.post('/articulos/lista-precios', { ids }, { responseType: 'blob' })
  return new Blob([data], { type: 'application/pdf' })
},
```

Mismo patrón que `pedidos.ticketBlob()` (`stores/pedidos.ts:213-216`).

### `ArticulosListView.vue`

- Botón nuevo **"Compartir Lista"** en la barra de selección (`ArticulosListView.vue:539-557`),
  junto a "Mover a catálogo" y "Eliminar", visible solo con `seleccionados.length > 0`.
- Al hacer clic:
  1. Si hay más de 100 artículos seleccionados (adición técnica 4), se muestra un aviso de
     confirmación —mismo componente de diálogo simple que ya usan Eliminar y Mover— con el conteo
     y un texto de "puede tardar unos segundos en generarse"; botones Cancelar / Continuar. Con
     100 o menos, se salta este paso y se continúa de inmediato.
  2. Se pide el PDF: `const blob = await articulos.listaPreciosBlob([...seleccionados.value])`.
  3. Se comparte de inmediato, **sin texto acompañante**:
     `await compartirArchivo(blob, `lista-precios-${fechaHoyISO}.pdf`)` — igual que el resto de los
     PDF de escritorio (cotización, factura): sin texto no hay canal que elegir, así que el
     respaldo es dejar el archivo descargado, sin abrir WhatsApp por su cuenta
     ([`compartir.ts:38-43`](../frontend/src/lib/compartir.ts)).
  4. Si el resultado es `'descargado'`, se muestra un aviso: "Lista descargada.". Si la generación
     falla en el servidor, se muestra "No se pudo generar la lista de precios." y la selección se
     conserva para reintentar.
- El botón muestra un estado de carga ("Generando…") mientras dura el paso 2, y queda deshabilitado
  ese rato para evitar clics repetidos.
- **La selección no se limpia automáticamente al terminar**, a diferencia de Eliminar y Mover: esos
  dos cambian el listado en el servidor y por eso vacían la selección; compartir no cambia nada, y
  el usuario puede querer generar la lista de nuevo o ajustar qué artículos lleva.

## Fuera de alcance

- **Un mecanismo de "seleccionar catálogo completo" independiente de la paginación.** Se logra
  filtrando por catálogo (034) y usando "seleccionar todos"; la selección sigue sin sobrevivir a
  cambios de página, búsqueda u orden, igual que en 021 y 034.
- **Guardar o recordar listas de precios generadas.** Cada PDF es efímero y no deja rastro en base
  de datos.
- **Elegir qué columnas o qué precio mostrar.** Siempre Nombre, Modelo y precio distribuidor con
  IVA; no hay variante con el precio directo.
- **Enviar la lista por correo o abrir WhatsApp con un texto propio.** Solo el menú nativo de
  compartir del sistema operativo, sin acompañarlo de un mensaje.
- **Un límite duro de artículos por lista.** Más de 100 solo dispara un aviso, nunca bloquea la
  generación.
- **Exportar a otro formato** (Excel, CSV, imagen). Solo PDF.
- **Marca de agua, contraseña o cualquier protección del PDF.**
- **Subir, cambiar o quitar la imagen del artículo desde esta pantalla.** La miniatura solo lee la
  que ya exista; para tenerla, gestionarla o reemplazarla se sigue usando el flujo de 020.
- **Un ícono o marcador genérico para "sin foto".** El artículo sin imagen deja la celda vacía, no
  una silueta ni un texto sustituto.
- **Recortar, encuadrar o editar la imagen para la miniatura.** Se usa la foto ya guardada tal cual,
  solo reducida de tamaño; no hay selección de la parte de la imagen que se muestra.
- **Registrar en el log una imagen faltante o dañada** al generar la lista de precios. Es
  decorativa: la ausencia de una miniatura no es un error que valga la pena anotar (a diferencia
  del logo del emisor en 019).
- Roles, permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Criterios de aceptación

1. Con uno o más artículos marcados en `/articulos`, la barra de selección muestra "Compartir
   Lista" junto a "Mover a catálogo" y "Eliminar".
2. Al hacer clic, se genera un PDF con la miniatura de la foto (si la tiene), nombre, modelo y
   precio distribuidor con IVA de cada artículo seleccionado.
3. Si todos los artículos seleccionados pertenecen al mismo catálogo, el PDF no muestra subtítulos
   de sección. Si pertenecen a más de un catálogo, aparecen agrupados bajo un subtítulo por
   catálogo.
4. Dentro de cada sección (o de la lista completa, si no hay secciones), los artículos aparecen
   ordenados alfabéticamente por nombre.
5. El precio mostrado es siempre el precio distribuidor con IVA incluido, nunca el precio directo
   ni un precio sin IVA.
6. Un artículo sin precio distribuidor configurado aparece en la lista con `$0.00`, no se omite.
7. El PDF lleva el logotipo del emisor en el encabezado, igual que cotizaciones y facturas, y la
   fecha de generación visible.
8. El PDF incluye al pie la leyenda de vigencia de precios con la fecha de generación.
9. Al terminar de generarse, se invoca el menú nativo de compartir de Windows 11 con el PDF como
   único archivo adjunto, sin ningún texto acompañante.
10. Si el menú nativo no está disponible, el PDF se descarga y se muestra un aviso de que se
    descargó; no se abre WhatsApp automáticamente.
11. Seleccionar más de 100 artículos y pulsar "Compartir Lista" muestra un aviso de confirmación
    antes de generar el PDF; seleccionar 100 o menos genera el PDF de inmediato sin ese aviso.
12. Un lote con un artículo ajeno o inexistente responde `422` y no genera ningún PDF.
13. Tras compartir o descargar la lista, la selección de artículos se mantiene tal como estaba.
14. El PDF se genera igual con el emisor vacío o sin logos cargados, sin error.
15. Un artículo con imagen aparece en el PDF con una miniatura de su foto en la primera columna de
    su fila, reducida (no la imagen a tamaño completo).
16. Un artículo sin imagen, o cuyo archivo de imagen ya no está en disco, deja esa celda vacía sin
    romper el PDF y sin dejar rastro en el log.
17. Pint corre sin errores sobre el backend, ESLint y Prettier sobre el frontend, la suite de Pest
    sigue pasando, y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

Los 11 primeros son las asunciones funcionales aceptadas al definir la historia; del 12 al 16, las
cinco adiciones técnicas resueltas; del 17 al 20, la miniatura de imagen agregada en una revisión
posterior.

1. La selección de artículos para la lista de precios usa los mismos checkboxes que ya existen en
   la tabla de artículos, sin crear un mecanismo de selección nuevo.
2. "Seleccionar un catálogo" se logra filtrando la tabla por ese catálogo y usando "seleccionar
   todos" de la cabecera, no con una opción independiente de "catálogo completo".
3. La selección solo cubre los artículos de la página actual visible, igual que las demás acciones
   en lote.
4. El botón "Compartir Lista" vive en la misma barra de acciones en lote que "Mover a catálogo" y
   "Eliminar", visible solo con al menos un artículo marcado.
5. El precio impreso es el precio distribuidor con IVA incluido, no el precio directo ni un precio
   sin IVA.
6. Un artículo seleccionado sin precio distribuidor configurado aparece igual en la lista, con
   `$0.00`, no se excluye.
7. El PDF es una lista simple de artículo y precio, sin cantidades ni importes.
8. El PDF reutiliza el logotipo y el estilo de los demás documentos, con encabezado "Lista de
   precios" y sin folio ni datos de cliente.
9. La lista de precios es un documento efímero: no se guarda ningún registro ni se asocia a ningún
   cliente.
10. Compartir reutiliza tal cual `compartirArchivo()`, sin ningún flujo de compartir nuevo.
11. El nombre del archivo generado es genérico (`lista-precios-{fecha}.pdf`), no personalizado por
    cliente ni catálogo.
12. **(Adición técnica)** La lista se ordena automáticamente por nombre en vez de conservar el
    orden en que se fueron marcando los checkboxes.
13. **(Adición técnica)** Si la selección incluye artículos de más de un catálogo, el PDF los
    agrupa en secciones con un subtítulo por catálogo; si es de uno solo, no se muestra ningún
    subtítulo.
14. **(Adición técnica)** El PDF lleva al pie una leyenda de vigencia de precios con la fecha de
    generación.
15. **(Adición técnica)** Seleccionar más de 100 artículos muestra un aviso de confirmación antes
    de generar el PDF, sin bloquear la operación.
16. **(Adición técnica)** La fecha de generación del PDF se imprime de forma visible en el
    encabezado del documento.
17. Se agrega una miniatura de la fotografía del artículo (si la tiene) en una columna nueva, la
    primera de la tabla, a la izquierda del nombre.
18. La miniatura es una copia reducida de la imagen ya guardada del artículo (020), a un tamaño
    bastante menor que el de esa imagen, no la original a tamaño completo — para no inflar el peso
    ni el tiempo de generación del PDF cuando la lista lleva muchas fotos.
19. Un artículo sin imagen, o cuyo archivo de imagen ya no está en disco, deja esa celda vacía, sin
    ícono sustituto y sin registrar nada en el log: es decorativa, no algo que deba avisarse como
    lo hace un logo faltante.
20. No se ofrece ninguna forma de excluir la miniatura, elegir su tamaño o recortarla desde esta
    pantalla; aparece siempre que el artículo tenga una imagen guardada.

## Estado de implementación

Implementada el 2026-08-22, en dos partes.

- **Primera versión** (supuestos 1-16): endpoint, plantilla, botón "Compartir Lista" y su diálogo
  de aviso. Verificada en un navegador real (Playwright/Chromium contra `php artisan serve` y
  `npm run dev`, con usuario y artículos de prueba creados y eliminados al terminar): seleccionar
  artículos y pulsar "Compartir Lista" generó el PDF (`200`, `application/pdf`) y, sin menú nativo
  de compartir disponible en el entorno de prueba, se descargó mostrando "Lista descargada.".
  Desplegada a producción el mismo día (commit `d7af4b7`).
- **Miniatura de imagen** (supuestos 17-20), agregada el mismo día a partir de una petición de
  seguimiento: `ImagenArticuloService::miniaturaBase64()`, la columna nueva en la plantilla y el
  cambio en `ArticuloController::listaPrecios`. Sin cambios de frontend.

**Verificación**: `php artisan test` completo en verde (614 pruebas, incluidas las 4 nuevas de
`miniaturaBase64` en `ArticuloImagenesTest.php` y las 2 de contenido con/sin miniatura en
`ArticulosTest.php`); Pint limpio sobre los archivos tocados. No se generó un PDF real con una
fotografía para inspección visual en esta ronda —las pruebas comprueban que la miniatura se
incrusta como una data URI válida y que reduce el tamaño de la imagen original, no cómo se ve
maquetada la columna—, así que la maquetación final (si 15×15 mm y los anchos de columna quedan
bien a simple vista) queda pendiente de una revisión visual la próxima vez que se genere una lista
con artículos que sí tengan foto.
