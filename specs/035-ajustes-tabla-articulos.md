# Spec: Filtro de catálogo fuera de la tabla, columna de utilidad y nombre completo en artículos

## Historia de usuario

Como usuario que trabaja todos los días con el listado de `/articulos`, quiero acotarlo por
catálogo sin que ese control ocupe una columna de la tabla, quiero ver de un vistazo el porcentaje
de utilidad de cada artículo sin tener que abrir su ficha, y quiero leer el nombre completo de cada
artículo aunque sea largo, en vez de que se corte con puntos suspensivos.

## Objetivo / Alcance

Tres cambios sobre `/articulos`, que comparten pantalla y se resuelven juntos:

1. **El filtro de catálogo sale de la tabla** y se coloca junto al buscador global, arriba de la
   tabla. Deja de existir la columna "Catálogo" que agregó
   [034](034-filtro-catalogo-y-mover-lote-articulos.md): ni su cabecera ni su celda de filtro. El
   mecanismo de filtrado no cambia en nada más que su ubicación en la pantalla — sigue siendo el
   mismo `<select>` con "Todos los catálogos", sigue recargando de inmediato al elegir una opción y
   sigue contando para "Limpiar filtros" y para "N artículos con los filtros aplicados".
2. **Columna nueva "Utld"**, con el porcentaje de utilidad directa efectiva de cada artículo (la
   misma que ya calcula el sistema para el precio directo: la propia del artículo si la tiene, si
   no la de su catálogo). Se agrega después de "P Dist" y antes de "Acciones", y es ordenable por
   su cabecera igual que Costo, P Directo y P Dist.
3. **El nombre del artículo se muestra completo**, envuelto en las líneas que haga falta, en vez de
   recortado con "...". La columna conserva el mismo ancho que tiene hoy; lo que cambia es que una
   fila con un nombre largo crece de alto en vez de recortar el texto.

**No se toca la columna "Costo"** (costo interno del artículo: lo que cuesta el aparato con
descuento más la goma). Se consideró agregar una columna de precio con IVA incluido y se descartó:
ver "Fuera de alcance".

Con estos tres cambios la tabla vuelve a tener 8 columnas: casilla, Nombre, Modelo, Costo, P
Directo, P Dist, Utld, Acciones — el mismo número que tiene hoy (034 quitó Catálogo(+1) para poner
Utld(+1) en su lugar), así que sigue viéndose completa sin scroll horizontal en escritorio sin
necesidad de acortar ninguna cabecera más.

## Backend (Laravel)

### Ordenar por "Utld" (utilidad porcentaje efectivo)

`ArticuloController::ORDENACIONES` (`ArticuloController.php:59-64`) gana una clave nueva:

```php
'utilidad_porcentaje_efectivo' => 'COALESCE(utilidad_porcentaje, '
    . '(SELECT utilidad_porcentaje FROM catalogos WHERE catalogos.id = articulos.catalogo_id))',
```

- Es la misma regla de herencia que ya usa `PrecioArticuloCalculator::utilidadEfectiva()` (el
  porcentaje propio del artículo si lo tiene; si no, el de su catálogo), expresada como SQL para
  poder ordenar por ella sin traer todos los artículos a PHP.
- Va como **subconsulta correlacionada**, no como `JOIN`: un `JOIN` contra `catalogos` haría
  ambiguas las referencias a columnas que existen en las dos tablas (`nombre`, entre otras, ya que
  `filtro_nombre` y el buscador global filtran por `nombre` sin calificar la tabla). La subconsulta
  no tiene ese problema porque no agrega columnas a la consulta principal.
- No necesita ningún cambio en `filtrarPorColumna` ni en `filtrarBusqueda`: `ordenar()` ya aplica
  cualquier clave de `ORDENACIONES` que reciba de forma genérica
  (`orderByRaw("$expresion $direccion")`).
- **No se agrega filtro de rango para "Utld"**: no hay una entrada nueva en `RANGOS`
  (`ArticuloController.php:74-79`). Igual que Costo, P Directo y P Dist, la columna se puede
  ordenar pero no acotar por un mínimo/máximo.
- `ArticuloResource` no cambia: `utilidad_porcentaje_efectivo` ya viaja en la respuesta desde 011
  (`ArticuloResource.php:36-39`), y el listado ya carga la relación `catalogo` con
  `->with('catalogo.proveedor')` (`ArticuloController.php:97`), así que el dato ya está disponible
  hoy para cada artículo de la página.

### El filtro de catálogo no cambia de lado del servidor

Sigue siendo `filtro_catalogo_id`, aplicado exactamente igual que desde 025/034
(`ArticuloController.php:589-592`). Esta historia no toca una sola línea de ese código.

## Frontend (Vue 3)

### El filtro de catálogo sale de la tabla

- Se quita de `ArticulosListView.vue` la columna "Catálogo" completa: su `<TableHead>` en la fila
  de cabeceras (`ArticulosListView.vue:577`) y su `<TableHead>` con el `CatalogoSelect` en la fila
  de filtros (`ArticulosListView.vue:612-620`). La tabla vuelve a tener el número de columnas que
  tenía antes de 034 más la nueva de Utld.
- El `CatalogoSelect` se mueve al bloque de arriba de la tabla, junto al `Input` del buscador
  global (`ArticulosListView.vue:499-504`), en el mismo renglón. Usa los mismos props que ya tenía
  en la tabla (`incluir-todos`, `placeholder="Todos los catálogos"`), pero **sin** `size="sm"`: ese
  tamaño se eligió en 034 porque la celda de la fila de filtros era angosta
  (`ArticulosListView.vue:617`); fuera de la tabla no hay esa restricción, así que usa el tamaño
  por defecto del componente, igual que el buscador de al lado.
- `onFiltroCatalogo` (`ArticulosListView.vue:208-211`) no cambia: sigue siendo la misma función,
  solo cambia desde qué elemento del template se dispara.
- La celda vacía que dejaban las columnas de dinero en la fila de filtros
  (`ArticulosListView.vue:624-628`) ya no necesita contar la columna de Catálogo, solo una celda
  menos.

### Columna nueva "Utld"

- `columnasNumericas` (`ArticulosListView.vue:192-196`) gana un cuarto elemento:
  `{ clave: 'utilidad_porcentaje_efectivo', etiqueta: 'Utld' }`. Como la cabecera y la fila de
  filtros de las columnas de dinero ya se dibujan iterando esa lista, la cabecera ordenable y la
  celda vacía de filtro de "Utld" salen solas, sin tocar el template de esas dos filas.
- La fila de datos de cada artículo gana una celda nueva junto a "P Dist" y antes de "Acciones",
  con el mismo ancho (`w-24`) que las otras tres columnas de dinero:
  `{{ articulo.utilidad_porcentaje_efectivo?.toFixed(2) ?? '—' }}%`. El `?? '—'` es solo defensivo
  — en la práctica el listado siempre trae el dato, porque `catalogo` va precargado — para no
  reventar si algún día cambiara.
- El `colspan="8"` de la fila de "sin resultados" (`ArticulosListView.vue:634`) **no cambia**: la
  tabla sigue teniendo 8 columnas, solo cambió cuál es la octava.

### `stores/articulos.ts`

- `ArticuloSort` (`articulos.ts:134`) gana `'utilidad_porcentaje_efectivo'` como cuarto valor
  posible, para que `toggleSort` y el resto del tipado acepten ordenar por la columna nueva.
- Nada más cambia en el store: `catalogoId` sigue viviendo en `ArticuloFiltros` exactamente igual
  que hoy, `paramsListado()` sigue mandando `filtro_catalogo_id` igual, y `hayFiltros` sigue
  contando igual. Mover el `<select>` en el template no mueve nada de estado.

### Nombre completo

- En la celda de nombre (`ArticulosListView.vue:657-664`) se quita la clase `truncate` del botón
  que abre la ficha del artículo. Sin esa clase el texto envuelve de forma normal en las líneas que
  haga falta, en vez de recortarse con elipsis.
- El atributo `title="articulo.nombre"` se quita junto con `truncate`: existía para poder leer el
  nombre completo al pasar el mouse sobre un texto recortado: con el texto ya completo en pantalla
  deja de tener función.
- El resto de columnas de esa fila (casilla, Modelo, Costo, P Directo, P Dist, Utld, Acciones) no
  cambia: cuando una fila crece de alto porque su nombre ocupa dos o más líneas, esas celdas siguen
  con su contenido de una sola línea, solo se estira la fila completa.

## Fuera de alcance

- **Una columna de "costo total" o precio con IVA incluido.** Se planteó durante esta historia y se
  descartó: la columna "Costo" que ya existe (costo interno del artículo) se queda exactamente como
  está, sin ningún cambio ni columna adicional.
- **Filtro de rango sobre "Utld"** (mínimo/máximo de utilidad). Solo se puede ordenar por esa
  columna, igual que Costo, P Directo y P Dist.
- **Recordar la posición o el ancho del filtro de catálogo** entre visitas. Sigue sin guardarse en
  `localStorage` ni en la URL, mismo criterio que el resto de los filtros (025).
- **Limitar el nombre a un máximo de líneas** (por ejemplo, con `line-clamp-2`). El nombre se
  envuelve en las líneas que necesite, sin tope.
- **Exportar "Utld" al CSV.** El CSV de exportación (`exportarCsv`) sigue con las mismas columnas de
  siempre; esta historia no le agrega ninguna.
- **Cambiar qué utilidad se muestra** (la del distribuidor, o un monto en pesos en vez de
  porcentaje). "Utld" es siempre el porcentaje de la utilidad **directa**.
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Estado de implementación

Implementada el 2026-08-21.

- **Archivos modificados**: `app/Http/Controllers/ArticuloController.php` (`ORDENACIONES`),
  `tests/Feature/ArticulosTest.php` (tres pruebas nuevas), y en el frontend
  `frontend/src/stores/articulos.ts` (`ArticuloSort`) y `frontend/src/views/ArticulosListView.vue`.
- **Corregido en verificación visual**: quitar la clase `truncate` de la celda de nombre no bastaba
  para que el texto envolviera. `TableCell` (`components/ui/table/TableCell.vue`) aplica
  `whitespace-nowrap` a **toda** celda por defecto desde 006, así que un nombre largo seguía en una
  sola línea y se desbordaba encima de las columnas vecinas en vez de recortarse o envolver. Se
  agregó `class="whitespace-normal"` explícito en esa `TableCell` (`ArticulosListView.vue`), que
  gana sobre la clase base porque `cn()` usa `tailwind-merge`.
- **Verificación**: Pint limpio; la suite de Pest completa pasa (599 tests, incluidas las 111 de
  `ArticulosTest.php`, tres nuevas para el orden por utilidad porcentaje efectiva: ambas
  direcciones, herencia del catálogo cuando el artículo no tiene una propia, y combinado con
  `filtro_nombre` para probar que la subconsulta no genera una columna `nombre` ambigua); ESLint y
  Prettier limpios; Vitest en verde (95 tests); `npm run build` compila la SPA completa con
  `vue-tsc`. **Se verificó visualmente en un navegador real** (Playwright/Chromium contra
  `php artisan serve`, `npm run dev` y un `mysqld` levantados para la ocasión, con un usuario,
  un proveedor, dos catálogos y tres artículos de prueba —uno con nombre largo— creados y
  eliminados al terminar, mismo criterio que 021/034): el filtro de catálogo aparece junto al
  buscador global y ya no dentro de la tabla; elegirlo acota el listado correctamente; la columna
  "Utld" ordena ascendente y descendente por el porcentaje de utilidad efectiva (confirmado con
  valores heredados del catálogo y propios mezclados); el nombre largo se envuelve en tres líneas
  sin desbordar ni empujar las demás columnas; la tabla no genera scroll horizontal a 1440px
  (`document.body.scrollWidth === document.body.clientWidth`); y la columna "Costo" no cambió de
  formato ni de valor.

## Criterios de aceptación

1. La fila de filtros de `/articulos` ya no tiene columna "Catálogo": ni cabecera ni celda de
   filtro. La tabla tiene 8 columnas: casilla, Nombre, Modelo, Costo, P Directo, P Dist, Utld,
   Acciones.
2. El filtro de catálogo aparece junto al buscador global, arriba de la tabla, como lista
   desplegable con "Todos los catálogos" y cada catálogo del usuario.
3. Elegir un catálogo en ese filtro acota el listado de inmediato, sin esperar una pausa de tecleo,
   se combina con Y con el resto de filtros y con la búsqueda, y regresa la paginación a la página
   1.
4. El filtro de catálogo sigue contando para "Limpiar filtros" y para "N artículos con los filtros
   aplicados", igual que antes de moverse.
5. La tabla tiene una columna "Utld" entre "P Dist" y "Acciones", con el porcentaje de utilidad
   directa efectiva de cada artículo.
6. El valor de "Utld" de un artículo con utilidad propia capturada es esa utilidad; el de un
   artículo sin utilidad propia es la utilidad del catálogo al que pertenece.
7. Hacer clic en la cabecera "Utld" ordena el listado por ese porcentaje, ascendente y luego
   descendente, igual que las demás columnas de dinero.
8. "Utld" no tiene caja de filtro en la fila de filtros: solo se puede ordenar.
9. El nombre de un artículo con texto largo se muestra completo, en dos o más líneas si hace falta,
   sin recortarse con "...".
10. La columna "Nombre" conserva el mismo ancho que tenía antes de este cambio.
11. La columna "Costo" no cambia: sigue mostrando el mismo valor (costo interno del artículo) que
    mostraba antes de esta historia.
12. En escritorio (≥1280px), con las ocho columnas y su fila de filtros, la tabla se sigue viendo
    completa **sin barra de desplazamiento horizontal**.
13. Pint corre sin errores sobre el código de backend, ESLint y Prettier sobre el de frontend, la
    suite de Pest sigue pasando, y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

1. El filtro de catálogo se saca de la fila de filtros de la tabla y se coloca en la misma fila
   donde ya está el buscador global, como lista desplegable.
2. El comportamiento del filtro de catálogo no cambia en nada más que su posición en la pantalla:
   sigue recargando de inmediato, sigue regresando a la página 1, sigue contando para "Limpiar
   filtros" y para "N artículos con los filtros aplicados".
3. Al sacar la columna "Catálogo", la tabla queda en 7 columnas base antes de sumar "Utld".
4. La columna "Costo" que ya existe no cambia. No se agrega ninguna columna de "costo total" ni de
   precio con IVA incluido — se planteó durante la conversación y se descartó explícitamente.
5. La columna nueva "Utld" muestra el porcentaje de utilidad **directa** efectiva del artículo
   (la propia si la tiene, si no la de su catálogo), no la utilidad del distribuidor ni un monto en
   pesos.
6. "Utld" se coloca después de "P Dist" y antes de "Acciones".
7. "Utld" es ordenable por su cabecera, igual que Costo, P Directo y P Dist.
8. "Utld" no lleva filtro de rango en la fila de filtros, solo ordenación.
9. El nombre del artículo se envuelve en las líneas que haga falta (sin límite de dos), sin cambiar
   el ancho de columna que tiene hoy.
10. Al envolver el nombre en varias líneas, la fila crece de alto para acomodarlo; el resto de
    columnas de esa fila no cambia su comportamiento.
11. Con Catálogo fuera (−1) y Utld dentro (+1), la tabla se queda en 8 columnas, las mismas que
    tiene hoy, así que sigue sin scroll horizontal sin necesidad de acortar ninguna cabecera más.
12. **(Adición técnica)** Ordenar por "Utld" no requiere pedirle ningún dato nuevo al servidor: el
    porcentaje de utilidad directa efectiva ya viaja hoy en la respuesta del listado para cada
    artículo. Lo único que falta es enseñarle al servidor a **ordenar** por ese número.
13. **(Adición técnica)** El servidor calcula el orden con una subconsulta correlacionada contra
    `catalogos` (no con un `JOIN`), para no arriesgar una ambigüedad de columnas: `catalogos` tiene
    su propia columna `nombre`, y el filtro de nombre y el buscador global ya filtran por `nombre`
    sin indicar de qué tabla, algo que un `JOIN` sí volvería ambiguo.
14. **(Adición técnica)** Mover el filtro de catálogo fuera de la tabla no toca el servidor en
    absoluto: es la misma casilla, el mismo parámetro `filtro_catalogo_id`, solo cambia su lugar en
    la pantalla.
