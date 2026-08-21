# Spec: Precio distribuidor (segundo precio de venta, sin goma)

## Historia de usuario

Como usuario único del sistema de facturación, quiero que cada artículo tenga, además del precio de
venta que ya calcula el sistema, un segundo precio para mis clientes distribuidores —que no pagan el
costo de la goma y llevan su propia utilidad—, para poder cotizar a cualquiera de los dos tipos de
cliente sin sacar la cuenta a mano cada vez.

## Objetivo / Alcance

Ampliar la cadena de precios de `Articulo` y `Catalogo` ([006](006-gestion-articulos.md),
[009](009-catalogos.md), [011](011-precio-proveedor-utilidad.md),
[014](014-costo-elaboracion-goma.md), [024](024-precios-sin-centavos.md)) para que cada artículo
tenga **dos** precios de venta calculados a partir del mismo costo:

- **Precio directo**: el que el sistema ya calcula hoy (`precio_unitario_sin_iva`). No cambia de
  comportamiento; esta historia solo le pone nombre.
- **Precio distribuidor**: nuevo, calculado sobre el costo **sin goma** y con su **propia** utilidad
  configurable, con la misma regla de redondeo al peso entero (024) y la misma regla de IVA por
  `objeto_imp` (024) que ya rigen al directo.

Esta historia cubre **calcular y mostrar** el precio distribuidor en el artículo (alta, edición,
listado, catálogo, ficha de compartir). **No** incluye un campo de "tipo de cliente" en `Cliente` ni
la selección automática de uno de los dos precios al armar una cotización, factura o venta de
mostrador — eso queda para una historia futura, cuando exista esa clasificación de cliente.

## Cadena de cálculo

Con costo con descuento (el "costo neto") $200.00, costo de goma $20.00, utilidad directo 50%,
utilidad distribuidor 25%, IVA general (artículo con `objeto_imp` = "02"):

```
                              costo_con_descuento              $200.00
                                        │
                    ┌───────────────────┴───────────────────┐
                    │                                        │
              + costo_goma                              (sin goma)
                    │                                        │
         costo_total = $220.00                    costo_con_descuento = $200.00
                    │                                        │
       × (1 + utilidad_directo / 100)          × (1 + utilidad_distribuidor / 100)
         = techo2(220 × 1.50)                        = techo2(200 × 1.25)
         = $330.00 (sin IVA, crudo)                  = $250.00 (sin IVA, crudo)
                    │                                        │
        redondeo al peso entero (024)             redondeo al peso entero (024)
      con el mismo factorIva del artículo        con el mismo factorIva del artículo
                    │                                        │
    precio_unitario_sin_iva = $330.17           precio_distribuidor_sin_iva = $250.00
      → con IVA: $383.00                          → con IVA: $290.00
```

El redondeo al peso entero es el que hace que el precio directo con IVA salga en **$383.00** y no en
los $382.80 de una fórmula sin redondear: $330.00 × 1.16 = $382.80, que no es peso entero, así que
024 sube al siguiente ($383.00) exactamente igual que ya lo hace hoy con cualquier artículo. El
precio distribuidor sí cae en peso entero de forma exacta en este ejemplo ($250.00 × 1.16 =
$290.00), pero no siempre será así: si la utilidad distribuidor sube a 30%, $200 × 1.30 = $260.00 sin
IVA crudo, $260 × 1.16 = $301.60, que **no** es peso entero, y el sistema entrega
`precio_distribuidor_sin_iva = $261.21` → con IVA **$303.00**, no $301.60 (el primer candidato,
$260.34, da $301.99 con IVA — no alcanza el objetivo de $302, así que 024 sube un peso más). Es la
misma regla 024 que ya gobierna al precio directo; el precio distribuidor la hereda sin excepción.
Estos cuatro valores están verificados contra la implementación real, no son aritmética de mano —
viven además como casos del fixture compartido (ver "Fuente de verdad única de la fórmula").

En un artículo cuyo `objeto_imp` no causa IVA, ninguno de los dos precios lleva el 16% encima, igual
que ocurre hoy con el directo.

## Backend (Laravel)

### `Catalogo`

- **Nueva columna `utilidad_distribuidor_porcentaje`**: `decimal(5,2)`, obligatoria, con **valor por
  defecto 0**, mismo patrón que `utilidad_porcentaje` (011). Es la utilidad distribuidor por defecto
  de todos los artículos del catálogo.
- **`Catalogo::booted()`** (recálculo en bloque, `app/Models/Catalogo.php:59-114`) se amplía:
  - El bloque que ya se dispara con `wasChanged('descuento')` recalcula **ambos** precios de todos
    los artículos del catálogo (incluidos los que tienen utilidad propia, de cualquiera de los dos
    tipos), porque un cambio de descuento mueve el costo del que parten los dos.
  - Se agrega un bloque nuevo, paralelo al de `utilidad_porcentaje`, que se dispara con
    `wasChanged('utilidad_distribuidor_porcentaje')` y recalcula `precio_distribuidor_sin_iva`
    **solo** de los artículos cuyo `utilidad_distribuidor_porcentaje` es `NULL` (los que heredan).
- **Endpoint de previsualización de impacto** (`impacto-precios`, ver abajo) acepta también
  `utilidad_distribuidor_porcentaje` como parámetro opcional.

### `Articulo`

- **Nueva columna `utilidad_distribuidor_porcentaje`**: `decimal(5,2)`, `nullable`. `NULL` = hereda
  la del catálogo; un valor = utilidad distribuidor propia del artículo. Misma herencia viva que
  `utilidad_porcentaje` (011).
- **Nueva columna `precio_distribuidor_sin_iva`**: `decimal(10,2)`, calculada y **persistida**, mismo
  criterio que `precio_unitario_sin_iva`: se recalcula en el alta, la edición, la importación CSV, el
  aumento de costos y los dos recálculos en bloque de `Catalogo::booted()`.
- **Nuevo accessor `precioDistribuidorConIva`**: espejo exacto de `precioUnitarioConIva`
  (`app/Models/Articulo.php:132-140`) — mismo `PrecioArticuloCalculator::factorIva($this->objeto_imp)`,
  no persistido.
- El precio distribuidor **no** entra en `costoTotal`, `utilidad` ni `dineroInvertido` /
  `beneficioPotencial`: esos accessors siguen midiéndose contra el costo con goma y el precio directo,
  porque son los que ya reflejan lo que de verdad se vende y se tiene en existencia hoy.

### `PrecioArticuloCalculator`

- **`utilidadDistribuidorEfectiva(Articulo $articulo, Catalogo $catalogo): float`**: espejo exacto de
  `utilidadEfectiva` (líneas 180-183), leyendo `utilidad_distribuidor_porcentaje` en vez de
  `utilidad_porcentaje`.
- **`calcularCadena()`** (líneas 193-210) gana un parámetro `float $utilidadDistribuidorPorcentaje` y
  el resultado gana la clave `precio_distribuidor_sin_iva`, calculada reutilizando las mismas
  funciones puras ya existentes (`precioVentaSinIva`, `redondearAPesoEntero`, `factorIva`) sobre
  `costo_con_descuento` **sin sumarle `costoGoma`**:

  ```php
  $precioDistribuidorCrudo = self::precioVentaSinIva($costo, $utilidadDistribuidorPorcentaje);
  $precioDistribuidor = self::redondearAPesoEntero($precioDistribuidorCrudo, self::factorIva($objetoImp));
  ```

  No se agrega ninguna función nueva de redondeo o de IVA: el precio distribuidor pasa por las mismas
  `techo2` y `redondearAPesoEntero` ya verificadas en 011 y 024, solo que partiendo de un costo base
  distinto (sin goma) y un porcentaje distinto.
- Todos los llamadores de `calcularCadena()` (`ArticuloController::store/update/importarCsv`,
  `Catalogo::booted()`, `CatalogoProveedorController::proyectar()`) pasan el nuevo parámetro y
  persisten `precio_distribuidor_sin_iva` junto a `precio_unitario_sin_iva`.

### Endpoints

Sin rutas nuevas. Cambios sobre las existentes:

- `GET /api/v1/catalogos-proveedor/{catalogo}/impacto-precios`
  (`CatalogoProveedorController::impactoPrecios`) acepta `utilidad_distribuidor_porcentaje` opcional
  (cae al valor actual del catálogo si no se manda) y la vista previa por artículo
  (`proyectar()`, líneas 166-183) incluye `precio_distribuidor_sin_iva`.
- `POST /api/v1/catalogos-proveedor/{catalogo}/aumentar-costos`
  (`CatalogoProveedorController::aumentarCostos`) persiste también `precio_distribuidor_sin_iva` al
  recorrer los artículos, porque `proyectar()` ya lo trae calculado.
- `POST` / `PUT /api/v1/articulos[/{id}]` aceptan `utilidad_distribuidor_porcentaje` opcional; no
  aceptan `precio_distribuidor_sin_iva` (se ignora en silencio, mismo patrón que
  `precio_unitario_sin_iva` en 011).
- `POST /api/v1/catalogos-proveedor/{catalogo}/articulos/importar-csv` y
  `GET /api/v1/articulos/exportar-csv`: ver columnas CSV abajo.
- `GET /api/v1/articulos` gana `precio_distribuidor_sin_iva` como cuarta columna ordenable de
  `?sort=` (ver "Frontend", listado).

### Columnas CSV

`utilidad_distribuidor_porcentaje` se agrega **al final**, después de `tamano_goma`, por la misma
razón por la que `tamano_goma` se agregó al final en 014: un CSV de 8 columnas de esta historia deja
de ser el de antes, pero uno de 7 columnas (sin esta) sigue siendo importable, porque la celda
faltante se trata igual que una celda vacía (hereda del catálogo):

```
nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje,tamano_goma,utilidad_distribuidor_porcentaje
```

- Opcional, igual que `utilidad_porcentaje`: celda vacía = hereda la del catálogo destino; con valor
  se guarda como utilidad distribuidor propia del artículo.
- `precio_distribuidor_sin_iva` no viaja en el CSV en ninguna dirección, mismo criterio que
  `precio_unitario_sin_iva`.

### Validaciones

`utilidad_distribuidor_porcentaje` sigue exactamente las mismas reglas que `utilidad_porcentaje` en
cada contexto:

- En `Articulo` (`StoreArticuloRequest`, `UpdateArticuloRequest`, fila de CSV): `nullable`, numérico,
  `gte:0`, `lte:999.99`, máximo 2 decimales.
- En `Catalogo` (`StoreCatalogoRequest`, `UpdateCatalogoRequest`): igual que `utilidad_porcentaje` —
  obligatorio, con `prepareForValidation` normalizando `null`/`''` a `0`, mismas cotas.
- En `impacto-precios`: `nullable`, mismas cotas.

### `ArticuloResource` y `CatalogoResource`

`ArticuloResource` (`app/Http/Resources/ArticuloResource.php`) agrega:
`utilidad_distribuidor_porcentaje`, `utilidad_distribuidor_porcentaje_efectivo` (vía
`utilidadDistribuidorEfectiva`), `precio_distribuidor_sin_iva`, `precio_distribuidor_con_iva`.

`CatalogoResource` agrega `utilidad_distribuidor_porcentaje`.

### Migración de esquema y de datos

Una sola migración, siguiendo el patrón de 011 (schema + backfill en el mismo archivo):

1. `catalogos` gana `utilidad_distribuidor_porcentaje` `decimal(5,2)` default `0`. Los catálogos
   existentes quedan en 0%, igual que arrancó `utilidad_porcentaje` en 011.
2. `articulos` gana `utilidad_distribuidor_porcentaje` `decimal(5,2)` nullable y
   `precio_distribuidor_sin_iva` `decimal(10,2)`.
3. Se recorre cada artículo existente y se recalcula `precio_distribuidor_sin_iva` con
   `PrecioArticuloCalculator::calcularCadena()`, con `utilidad_distribuidor_porcentaje` en `NULL`
   (hereda el 0% del catálogo, así que todos los artículos arrancan con precio distribuidor igual a
   su costo sin goma, sin markup, hasta que se capture la utilidad real por catálogo o por artículo).

### Fuente de verdad única de la fórmula

`shared/fixtures/precios-articulos.json` (011) gana `utilidad_distribuidor_porcentaje` como entrada y
`precio_distribuidor_sin_iva` como resultado esperado en cada caso existente y en los casos nuevos que
hagan falta para cubrir el redondeo del distribuidor por separado (un caso donde el directo cae en
peso entero exacto y el distribuidor no, y viceversa). PHPUnit y Vitest lo siguen leyendo del mismo
archivo.

## Frontend (Vue 3)

### Módulo de cálculo compartido

`src/lib/precioArticulo.ts` — `calcularCadena()` gana el parámetro `utilidadDistribuidorPorcentaje` y
el resultado gana `precio_distribuidor_sin_iva`, mismo cambio que en `PrecioArticuloCalculator`,
reutilizando `precioVentaSinIva`, `redondearAPesoEntero` y `factorIva` ya existentes. El fixture
compartido cubre ambas implementaciones.

### `/articulos` (listado)

- Nueva columna **"Precio distribuidor"**, después de "Precio de venta". `colspan` del renglón vacío
  pasa de 6 a 7.
- Se agrega a `columnasNumericas` (`ArticulosListView.vue:177-179`), por lo que hereda gratis la
  ordenación por encabezado y el filtro de rango de 025 (`ORDENACIONES['precio_distribuidor_sin_iva']`
  y `RANGOS['distribuidor']` nuevos en `ArticuloController`).

### `/articulos/crear` y `/articulos/:id/editar`

- Se captura **`utilidad_distribuidor_porcentaje`** (`Input` numérico, opcional), mismo patrón que
  `utilidad_porcentaje`: placeholder con la utilidad distribuidor heredada del catálogo cuando está
  vacío.
- El bloque de "Cadena de cálculo" se amplía con la rama distribuidor: costo sin goma → utilidad
  distribuidor → IVA (si aplica) → precio final distribuidor, con el mismo criterio de no mostrar
  renglones de $0.00 ni el de "Redondeo" cuando no aplica.
- El aviso de utilidad alta (032) también vigila la utilidad distribuidor efectiva.
- La comparación de discrepancia contra el valor guardado por el servidor (011) incluye
  `precio_distribuidor_sin_iva`.

### `/catalogos/crear` y `/catalogos/:id/editar`

- Campo **`utilidad_distribuidor_porcentaje`** (`Input` numérico, precargado en `0`), junto al de
  `utilidad_porcentaje`, con el mismo aviso no bloqueante de 032.
- El diálogo de confirmación de impacto antes de guardar (011) incluye el nuevo campo entre los que
  disparan la vista previa.
- La tabla de "Ver impacto" (`CatalogoFormView.vue:330-357`) gana la columna "Precio distribuidor".

### Ficha del artículo que se comparte al cliente

Dos pantallas muestran hoy la ficha del artículo al cliente y **nunca** el costo, el proveedor ni la
utilidad (`ArticuloDetalleDialog.vue` en escritorio, `MostradorArticuloView.vue` en el mostrador —
misma regla, documentada en ambos archivos desde 020 y 031). Las dos reciben el mismo tratamiento,
para no dejar una desincronizada de la otra:

- Se muestran **ambos precios con IVA** (`precio_unitario_con_iva` y `precio_distribuidor_con_iva`),
  cada uno con su etiqueta ("Precio" / "Precio distribuidor", con el mismo condicional de "con IVA"
  que ya existe según `objeto_imp`).
- El botón único "Compartir" se reemplaza por **dos botones**, uno por precio ("Compartir precio" /
  "Compartir precio distribuidor"), cada uno arma su propio texto compartible
  (`{{nombre}} — Modelo {{modelo}} — {{precio}}`) y usa el mismo mecanismo de compartir que ya tiene
  cada pantalla (el de `ArticuloDetalleDialog` con `navigator.share`/portapapeles directo, el de
  `MostradorArticuloView` vía `compartirArchivo`/`compartirTexto` de `lib/compartir.ts`).

### Tests

- El fixture compartido cubre la cadena distribuidor en ambas suites (PHPUnit, Vitest), igual que 011.
- `ArticuloFactory` recibe (opcionalmente) `utilidad_distribuidor_porcentaje` y deriva
  `precio_distribuidor_sin_iva` junto con el resto de la cadena.
- Cobertura de `Catalogo::booted()`: cambiar `utilidad_distribuidor_porcentaje` del catálogo mueve
  solo a los artículos que la heredan; cambiar `descuento` mueve ambos precios de todos los artículos.
- Cobertura del CSV: fila con la nueva columna vacía hereda, con valor guarda propio; exportar e
  reimportar no pierde el dato.
- Cobertura de la ordenación de `GET /api/v1/articulos` por la nueva columna, en ambas direcciones.

## Fuera de alcance

- **Tipo de cliente** en `Cliente` (directo / distribuidor) y cualquier selección automática del
  precio correspondiente en Cotizaciones, Facturas o Venta de mostrador
  ([007](007-facturacion.md), [008](008-cotizaciones.md), [027](027-venta-mostrador-ticket.md)).
  Ambas pantallas de esas historias siguen usando `precio_unitario_sin_iva` sin cambios.
- **Descuento por cliente** ([015](015-descuento-permanente-cliente.md)) no se relaciona con esta
  historia: sigue siendo un porcentaje libre por cliente a nivel cotización, independiente de cuál de
  los dos precios de artículo se use.
- Reportes de rentabilidad por tipo de cliente.
- Historial de cambios de la utilidad distribuidor o del precio distribuidor.
- Un tercer precio o más de dos tipos de cliente.
- Cambiar la regla de redondeo al peso entero (024) o la regla de IVA por `objeto_imp`: el precio
  distribuidor las hereda tal cual, no se revisan ni se hacen opcionales.
- Inventario/existencias valuadas al precio o utilidad distribuidor: `dineroInvertido` y
  `beneficioPotencial` (017) siguen midiéndose contra el precio directo.

## Criterios de aceptación

1. Un artículo con costo con descuento $200.00, costo de goma $20.00, utilidad directo 50%, utilidad
   distribuidor 25% y `objeto_imp` "02" produce `precio_unitario_sin_iva = $330.17` (con IVA
   $383.00) y `precio_distribuidor_sin_iva = $250.00` (con IVA $290.00).
2. El mismo artículo con utilidad distribuidor 30% en vez de 25% produce
   `precio_distribuidor_sin_iva = $261.21` (con IVA $303.00), no $301.60: el redondeo al peso entero
   de 024 se aplica al distribuidor exactamente igual que al directo.
3. Un artículo sin utilidad distribuidor propia hereda la del catálogo; el formulario muestra el
   valor heredado como referencia mientras el campo está vacío.
4. Cambiar el descuento de un catálogo recalcula **ambos** precios de todos sus artículos, incluidos
   los que tienen utilidad propia de cualquiera de los dos tipos.
5. Cambiar la utilidad distribuidor de un catálogo recalcula el precio distribuidor **solo** de los
   artículos que la heredan; los que tienen utilidad distribuidor propia conservan su precio
   distribuidor. Cambiar la utilidad directo del catálogo no toca el precio distribuidor de ningún
   artículo, y viceversa.
6. Capturar una utilidad distribuidor negativa o mayor a 999.99 muestra un error de validación y no
   permite guardar; 0 y valores de tres dígitos dentro del rango sí se aceptan.
7. El listado `/articulos` muestra "Precio distribuidor" como columna adicional, ordenable
   ascendente y descendentemente, y filtrable por rango igual que las demás columnas de dinero.
8. El formulario de artículo muestra en vivo la cadena de cálculo del distribuidor (costo sin goma →
   utilidad distribuidor → IVA si aplica → precio final distribuidor), junto a la del directo.
9. El formulario de catálogo captura la utilidad distribuidor con el mismo aviso no bloqueante por
   encima de 400% que ya tiene la utilidad directo, y la vista previa de impacto muestra el precio
   distribuidor resultante.
10. Importar un CSV con la columna `utilidad_distribuidor_porcentaje` vacía hereda la del catálogo
    destino; con valor la guarda como propia del artículo. Un CSV de 7 columnas (sin esa columna)
    sigue siendo importable.
11. Exportar el listado genera un CSV con las 8 columnas, y ese archivo es reimportable sin pérdida.
12. La ficha del artículo que se comparte al cliente (escritorio y mostrador) muestra los dos precios
    con IVA, cada uno con su etiqueta, y ofrece un botón de compartir independiente por cada uno; el
    texto o archivo compartido por cada botón trae únicamente el precio que le corresponde.
13. Ningún archivo de Facturación, Cotizaciones ni Venta de mostrador cambia de comportamiento: siguen
    precargando líneas con `precio_unitario_sin_iva` (el precio directo).
14. Tras la migración, todos los artículos existentes tienen `precio_distribuidor_sin_iva` calculado
    (a partir de una utilidad distribuidor heredada de 0%) y ninguno queda con la columna vacía.
15. Pint, ESLint/Prettier y las suites de PHPUnit y Vitest corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. El "costo neto" de la historia es `costo_con_descuento`: el costo del artículo ya con el
   descuento del proveedor aplicado, **sin** la goma. No se modifica la importación de precios de
   proveedor ([011](011-precio-proveedor-utilidad.md)): el precio distribuidor se calcula a partir
   del costo que esa cadena ya produce.
2. La utilidad "directo" de la historia **es** `utilidad_porcentaje`, el campo que ya existe hoy en
   `Catalogo` y `Articulo` (011); no se crea ni se renombra nada para representarla.
3. El precio que el sistema ya calcula y muestra hoy (`precio_unitario_sin_iva`, con goma, con
   redondeo al peso entero de 024 y con IVA condicionado por `objeto_imp`) se conserva exactamente
   igual y pasa a entenderse como "precio directo"; no hay un cálculo paralelo para él.
4. El precio distribuidor se redondea al peso entero con la misma función `redondearAPesoEntero`
   (024) que el directo, aunque eso lo aleje del centavo exacto de una fórmula sin redondear (ver
   criterio de aceptación 2). Es una consecuencia directa de reutilizar la regla existente, no una
   excepción para el distribuidor.
5. El precio distribuidor lleva IVA únicamente en los artículos cuyo `objeto_imp` ya lo causa hoy en
   el precio directo (024); no hay una tasa fija de 16% aplicada a todos los artículos sin
   excepción.
6. Esta historia cubre solo el cálculo y la visualización del precio distribuidor en Artículos y
   Catálogos. El "tipo de cliente" en `Cliente` y la selección automática del precio en
   cotización/factura/venta de mostrador quedan fuera, para una historia futura.
7. La utilidad distribuidor se configura con la misma estructura de dos niveles que la utilidad
   directo: un valor por catálogo (`utilidad_distribuidor_porcentaje`, default 0) y un valor opcional
   por artículo que la sobrescribe, con herencia viva idéntica a 011.
8. La utilidad distribuidor se edita en el mismo formulario donde hoy se edita la utilidad directo
   (catálogo y artículo), como un campo hermano; no se agrega una pantalla nueva ni se mete en
   Configuración (que hoy solo maneja costo de goma y mensajes de ticket, ver 014).
9. `precio_distribuidor_sin_iva` se persiste en columna, igual que `precio_unitario_sin_iva`, y se
   recalcula en cascada en los mismos puntos donde hoy se recalcula el directo (alta, edición, CSV,
   aumento de costos, y los dos recálculos en bloque de `Catalogo::booted()`), en vez de calcularse al
   vuelo en cada lectura.
10. No se modifica la importación de listas de precios del proveedor: el precio distribuidor se
    calcula después, en el mismo paso donde hoy se calcula el precio directo, a partir del costo que
    esa importación ya produce.
11. La ficha de artículo que se comparte al cliente (`ArticuloDetalleDialog.vue`, el modal de
    escritorio confirmado explícitamente) y su equivalente de pantalla completa en el mostrador
    (`MostradorArticuloView.vue`, que documenta desde 031 seguir "la misma regla que la ficha del
    escritorio") reciben el mismo tratamiento: ambas ganan el segundo precio y el segundo botón de
    compartir, para no dejar una pantalla mostrando un solo precio mientras la otra muestra dos.
12. El texto o archivo que sale de cada botón de compartir lleva **un solo** precio (el que
    corresponde a ese botón), no los dos juntos: quien comparte elige antes de enviar cuál de los dos
    precios le corresponde a la conversación.
13. **(Adición técnica)** `PrecioArticuloCalculator::calcularCadena()` (backend) y `calcularCadena()`
    de `precioArticulo.ts` (frontend) se amplían en el mismo lugar en vez de crear una segunda función
    paralela: reciben la utilidad distribuidor como parámetro adicional y devuelven
    `precio_distribuidor_sin_iva` calculado con las mismas primitivas de redondeo y de IVA ya
    existentes (`techo2`, `redondearAPesoEntero`, `factorIva`), aplicadas sobre `costo_con_descuento`
    sin sumarle `costo_goma`.
14. **(Adición técnica)** `utilidadDistribuidorEfectiva()` se agrega como función espejo de
    `utilidadEfectiva()` en vez de generalizar ambas en una sola función parametrizada por tipo: son
    dos líneas de código cada una y una abstracción compartida no ahorraría nada.
15. **(Adición técnica)** La columna CSV nueva va al final (después de `tamano_goma`), replicando el
    criterio ya usado en 014 al agregar la columna de goma: un archivo exportado antes de esta
    historia sigue siendo importable sin cambios.
16. **(Adición técnica)** El recálculo en bloque de `Catalogo::booted()` para
    `utilidad_distribuidor_porcentaje` es un bloque `if` nuevo, paralelo al de `utilidad_porcentaje`
    (011), no una fusión de ambos: los dos disparadores son independientes (cambiar uno no debe mover
    los artículos que solo heredan el otro).
17. **(Adición técnica)** La migración agrega columnas y hace el backfill en el mismo archivo,
    siguiendo el patrón de la migración de 011: todos los artículos existentes recalculan su
    `precio_distribuidor_sin_iva` con `utilidad_distribuidor_porcentaje` en `NULL` (heredan el 0% con
    el que arrancan los catálogos existentes).
18. **(Adición técnica)** El fixture compartido `shared/fixtures/precios-articulos.json` (011) se
    amplía con las columnas del distribuidor en vez de crear un segundo archivo, para que un cambio
    en la fórmula común (`techo2`, `redondearAPesoEntero`) siga verificándose contra los mismos casos
    para los dos precios.
