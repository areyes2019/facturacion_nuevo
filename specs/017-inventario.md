# Spec: Inventario (existencias, faltantes, mínimos de reposición y movimientos)

## Historia de usuario

Como usuario registrado, quiero tener un inventario de los artículos que tengo en mi lista de
catálogos, para responder cuántas piezas tengo de un modelo, cuánto dinero tengo invertido, cuál es
el mínimo de un producto para volver a pedir, y cuánto beneficio en potencia tengo. Quiero que las
órdenes de compra ya pagadas puedan marcarse como "Recibidas" y entrar al inventario, poder
modificar las cantidades manualmente, y poder meter manualmente productos de la lista de artículos a
mi inventario.

## Objetivo / Alcance

Implementar el módulo de **Existencias** sobre los [Artículos](006-gestion-articulos.md) con su
[cadena de costos](011-precio-proveedor-utilidad.md), su [costo de goma](014-costo-elaboracion-goma.md)
y sus [catálogos por proveedor](009-catalogos.md), conectado a las
[Órdenes de compra](012-ordenes-compra.md) por el lado de las entradas y a
[Facturación](007-facturacion.md) y [Cotizaciones](008-cotizaciones.md) por el lado de las salidas.

Incluye: existencia, faltante pendiente, mínimo y máximo por artículo; historial de movimientos;
entrada automática al recibir una orden de compra pagada; salida automática al timbrar una factura o
marcar una cotización como entregada; vínculo opcional factura → cotización para que una misma venta
no descuente dos veces; ajustes manuales; sugerencia de reposición y generación de órdenes de compra
en borrador; y los cuatro totales de negocio.

**No** incluye: almacenes o ubicaciones múltiples, números de serie, lotes, caducidades, recepción
parcial por línea, costo promedio ponderado o PEPS, inventario de insumos de goma (explícitamente
fuera desde [014](014-costo-elaboracion-goma.md)), alertas por correo o WhatsApp, exportación, ni
multiempresa.

### Esta spec supera a la 012

[012-ordenes-compra.md](012-ordenes-compra.md) declara por escrito que el sistema no lleva inventario
y que marcar una orden como `recibida` no suma stock (secciones "Recepción de la mercancía", "Fuera
de alcance" y supuesto 29). **Esta spec revierte esa decisión.** Se agrega una nota al inicio de esas
secciones de la 012 remitiendo aquí, en lugar de dejar dos specs afirmando cosas opuestas.

### Las cuatro preguntas que este módulo responde

| Pregunta del usuario | Cómo se calcula |
|---|---|
| ¿Cuántos artículos tengo de este modelo? | `existencia` del artículo |
| ¿Cuánto dinero tengo invertido en inventario? | Σ `existencia` × costo total, sin IVA |
| ¿Cuál es el mínimo de un producto para volver a pedir? | `minimo` capturado por artículo, más la cantidad sugerida |
| ¿Cuánto beneficio en potencia tengo? | Σ `existencia` × (precio de venta sin IVA − costo total) |

Donde **costo total** es `costo_con_descuento + costo_goma` (el atributo calculado `costo_total` de
`Articulo`, ver [011](011-precio-proveedor-utilidad.md) y [014](014-costo-elaboracion-goma.md)) y el
precio de venta es `precio_unitario_sin_iva`. Todas las cifras del módulo son **sin IVA** y en pesos
mexicanos.

### Existencia y faltante pendiente: dos números positivos

La existencia **nunca es negativa**. Cuando una salida excede lo disponible, la existencia toca fondo
en `0` y el sobrante se acumula en un segundo contador, el **faltante pendiente**.

> Tienes 2 piezas y timbras una factura de 5 → `existencia = 0`, `faltante_pendiente = 3`.

Un faltante **no es una deuda con un cliente**: la mercancía ya salió físicamente (la salida ocurre
al entregar o al vender de mostrador). Es un **descuadre de registro** — la constancia de que el
sistema tenía menos piezas anotadas de las que realmente había. Esa distinción es la que justifica
que un ajuste manual lo borre: ver "Las tres reglas de movimiento".

Se eligió esto sobre una existencia negativa porque un número negativo obliga al usuario a
interpretar un signo, mientras que "tengo 0 y me faltan 3" se lee sin pensar. El estado interno es
equivalente; la lectura no.

### Valuación al costo de hoy

El inventario se valúa con el **costo actual** del artículo, no con el costo al que entró cada pieza.
Consecuencia deliberada: cambiar el `precio_proveedor` de un artículo o el `descuento` de su catálogo
**revalúa el inventario completo**, incluidas las piezas compradas antes a otro precio. El número
responde "cuánto me costaría reponer lo que tengo hoy".

Se descartó guardar el costo de cada entrada y se descartó el costo promedio ponderado. Es la
decisión que más maquinaria ahorra de toda la historia, y la que habría que revisar primero el día
que el negocio necesite valuación contable real.

## Backend (Laravel)

### Columnas nuevas en `articulos`

No hay tabla de existencias. Los cuatro números viven en la ficha del artículo, porque la lista de
inventario **es** la lista de artículos con cuatro columnas más:

- `existencia`: `unsignedInteger`, default `0`. Nunca negativa.
- `faltante_pendiente`: `unsignedInteger`, default `0`.
- `minimo`: `unsignedInteger`, default `0`. `0` significa "no me avises de este artículo".
- `maximo`: `unsignedInteger`, **nullable**. Techo al que se quiere rellenar. Si es `null`, el techo
  para la sugerencia es el propio `minimo`.

Todos los artículos existentes quedan en `0` tras la migración. **No hay carga retroactiva**: las
órdenes de compra ya marcadas como `recibida` antes de esta historia no suman nada. El inventario
arranca vacío y se construye de aquí en adelante (ver [[project_sistema_sin_produccion]] — no hay
datos reales que rescatar).

Ninguna de las cuatro columnas es editable desde el formulario de artículo: se mueven por los
endpoints de este módulo, que son los únicos que escriben el historial en el mismo acto.

### Modelo `MovimientoInventario`

Tabla `movimientos_inventario`, declarada con `protected $table` explícito — Eloquent pluraliza en
inglés y de `MovimientoInventario` inferiría `movimiento_inventarios`. Es la lección ya pagada en
005, 008 y 012; aquí se aplica de entrada.

- `user_id`, `articulo_id` (FK, `cascadeOnDelete` no: `restrictOnDelete` no aplica porque el artículo
  usa soft delete, así que la FK es normal y el historial sobrevive al borrado lógico).
- `tipo` (enum `TipoMovimientoInventario`): `entrada` | `salida` | `ajuste`.
- `motivo` (enum `MotivoMovimientoInventario`): `recepcion_orden`, `venta_factura`,
  `venta_cotizacion`, `cancelacion_factura`, `conteo_fisico`, `merma`, `devolucion`,
  `entrada_inicial`, `otro`.
- `cantidad`: `unsignedInteger`. Es la **magnitud** del movimiento; la dirección la da `tipo`. En un
  `ajuste` es la cantidad final capturada.
- `existencia_resultante` y `faltante_resultante`: `unsignedInteger`. El estado en que quedó el
  artículo **después** de aplicar el movimiento. Son los que hacen auditable el historial sin
  reconstruir nada.
- `nota`: `text`, nullable. Texto libre del usuario.
- `documentable_type` / `documentable_id`: `nullableMorphs`. Apunta a la `OrdenCompra`, `Factura` o
  `Cotizacion` que originó el movimiento; `null` en los ajustes manuales. Mismo patrón que el
  `Movimiento` de [Tesorería](010-tesoreria.md), que ya usa `morphOne` con `documentable`.
- Índice compuesto por `(articulo_id, id)`: el historial siempre se lee por artículo y en orden.

El historial es **solo de consulta**. No hay endpoints de edición ni borrado de movimientos; la
corrección de un error se hace con un ajuste manual nuevo, que queda registrado como tal.

### Las tres reglas de movimiento

Todo el módulo se reduce a tres operaciones sobre el par (`existencia`, `faltante_pendiente`). Están
concentradas en un único servicio, `InventarioService`, y **nadie más escribe esas columnas**.

**1. Entrada de N piezas** — recepción de orden de compra, devolución por cancelación de factura.

```
saldado    = min(N, faltante_pendiente)
faltante  -= saldado
existencia += (N − saldado)
```

Primero salda el faltante y solo el resto sube la existencia. Debes 3 y entran 10 → existencia 7,
faltante 0. Debes 3 y entran 2 → existencia 0, faltante 1.

**2. Salida de N piezas** — timbrado de factura sin cotización vinculada, cotización entregada.

```
descontado = min(N, existencia)
existencia -= descontado
faltante   += (N − descontado)
```

Nunca se bloquea la venta y nunca se produce un número negativo.

**3. Ajuste manual: fijar la cantidad final en N** — conteo físico, merma, devolución, entrada
inicial, alta manual.

```
existencia = N
faltante   = 0
```

El ajuste **fija**, no suma: el usuario captura cuántas piezas hay, no cuántas cambiaron. Y borra el
faltante porque un faltante es un descuadre de registro y el usuario acaba de medir la realidad con
sus manos; arrastrarlo después de contar sería conservar un error ya corregido.

> **Por qué el alta manual fija en lugar de sumar.** Meter un artículo al inventario por primera vez
> y ajustar uno existente son la misma operación con distinto punto de partida, así que ambas usan
> la regla 3. La "regla única de entrada" de la sección anterior gobierna las entradas **que suman
> una cantidad** (recepción y devolución), no las que declaran un total.

### Entradas: recepción de órdenes de compra

`POST /api/v1/ordenes-compra/{id}/recibir` ya existe y hoy solo cambia el estado
([OrdenCompraController](../backend/app/Http/Controllers/OrdenCompraController.php)). Ahora, además:

- Recorre las líneas de la orden, **descarta las que tienen `articulo_id` en `null`** (la columna es
  nullable desde 012 y una línea de texto libre no tiene existencia a la que sumar), **agrupa por
  artículo y suma las cantidades**, y aplica la regla de entrada a cada artículo resultante.
- Registra un `MovimientoInventario` por artículo —no por línea— con `tipo = entrada`,
  `motivo = recepcion_orden` y `documentable` apuntando a la orden.
- Sigue siendo **total** (todas las líneas completas), **irreversible** y **una sola vez**.

La agrupación por artículo es una **red defensiva**, no una funcionalidad visible: la regla de "un
artículo por línea" de [008](008-cotizaciones.md) vive únicamente en el componente `DocumentoLineas`
del frontend, y su propia adición técnica 31 deja constancia de que los Form Requests siguen
aceptando `articulo_id` repetido. Sin agrupar, dos líneas del mismo artículo podrían pisarse y perder
piezas en silencio.

Si la orden ya no está en `pagada` al momento de ejecutarse, la operación no hace nada y responde
con la orden tal como está (ver "Atomicidad").

### Salidas: facturas y cotizaciones

La mercancía sale del inventario cuando sale **físicamente**:

- **Factura**: al pasar a `timbrada` (`POST /api/v1/facturas/{factura}/timbrar`).
- **Cotización**: al marcarse `producto_entregado` (`POST /api/v1/cotizaciones/{cotizacion}/entregar`).

En ambos casos se agrupan las líneas por artículo, se descartan las que no tienen `articulo_id`, y se
aplica la regla de salida. El movimiento queda con `tipo = salida`, `motivo = venta_factura` o
`venta_cotizacion`, y `documentable` apuntando al documento.

**Una salida nunca bloquea la operación.** Timbrar no puede fallar por inventario: si no alcanza, se
genera faltante. Esa decisión es deliberada — el inventario arranca en cero y bloquear las ventas
hasta terminar de cargar existencias iniciales dejaría el sistema inutilizable durante días.

### El vínculo factura → cotización

**El vínculo ya existe y no se crea nada nuevo.** Desde [008](008-cotizaciones.md), el botón
"Facturar" del detalle de cotización lleva al formulario de factura con la cotización en la URL, y
`FacturaController::store` escribe `cotizaciones.factura_id`. Agregar un `cotizacion_id` a `facturas`
duplicaría el dato en dos columnas que pueden contradecirse, así que la 017 **lee la relación que ya
está ahí**: `Factura::cotizacion()` es un `hasOne` inverso sobre `cotizaciones.factura_id`.

Lo único que cambia en ese flujo es el **orden**: hoy el vínculo se escribe *después* de timbrar, y
el timbrado es justo el momento en que se decide si hay que descontar existencias. Se mueve dentro de
la transacción de creación, antes de timbrar; si no, toda factura nacida de una cotización se vería
como venta de mostrador y descontaría mercancía que la cotización va a descontar otra vez al
entregarse.

Los dos caminos de venta son reales — hay clientes con cotización que no piden factura, y hay ventas
de mostrador facturadas sin cotización previa.

**Regla: si hay cotización vinculada, la cotización manda siempre.**

| Caso | Quién descuenta |
|---|---|
| Factura sin cotización vinculada (mostrador) | La factura, al timbrarse |
| Factura con cotización vinculada | **Nadie** al timbrar. La cotización, al marcarse entregada |
| Cotización que nunca se factura | La cotización, al marcarse entregada |

La factura vinculada **nunca** mueve inventario, sin importar el estado de la cotización. Efecto
secundario aceptado: facturar por adelantado algo que aún no entregas no mueve nada — que es
correcto, porque la mercancía sigue en tu bodega.

Se eligió esta regla sobre una que rastreara documento por documento quién descontó, porque cabe en
una línea y no tiene excepciones que recordar seis meses después.

### Cancelación de una factura

`POST /api/v1/facturas/{factura}/cancelar` devuelve las piezas al inventario **solo si la factura no
tiene cotización vinculada**. Si la tiene, no devuelve nada: no se devuelve lo que nunca salió.

La devolución ocurre cuando la cancelación queda **aceptada** por el SAT, no cuando se solicita:
mientras el estado de cancelación esté `pending`, la factura sigue vigente y la mercancía sigue
fuera. Por eso el mismo control corre también en el refresco del estado de cancelación que 007 hace
al abrir el detalle, protegido para no devolver dos veces.

La devolución es una **entrada normal** (regla 1): salda primero el faltante y el resto sube la
existencia. Movimiento con `tipo = entrada`, `motivo = cancelacion_factura`, `documentable` la
factura.

Se descartó que cada documento recordara exactamente cuánto quitó de existencia y cuánto de faltante
para reponerlo al revés, porque produce estados imposibles:

> Tienes 10. Facturas 5 → existencia 5, faltante 0. Facturas otras 8 → existencia 0, faltante 3.
> Cancelas la **primera** factura.
>
> Reponiendo "lo que esa factura hizo": +5 a existencia → **existencia 5 con faltante 3**, es decir,
> tienes piezas en la mano y simultáneamente debes piezas.
>
> Como entrada normal: entran 5, saldan el faltante de 3, quedan 2 → **existencia 2, faltante 0**.
> Que es la verdad: tenías 10, vendiste 8, la otra venta se canceló.

La regla simple no solo es más barata: es más correcta.

### Ajustes manuales

`POST /api/v1/inventario/{articulo}/ajuste`, body `{ cantidad, motivo, nota? }`.

- `cantidad` es la **cantidad final** que queda, no la diferencia. Contaste 10, escribes 10.
- `motivo` es **obligatorio**, de la lista cerrada `conteo_fisico` | `merma` | `devolucion` |
  `entrada_inicial` | `otro`. Los motivos automáticos (`recepcion_orden`, `venta_*`,
  `cancelacion_factura`) se rechazan con `422`: no se puede falsificar el origen de un movimiento.
- `nota` es opcional, texto libre.
- **Meter un artículo al inventario por primera vez usa este mismo endpoint.** No hay un alta
  aparte: eliges el artículo en el buscador y capturas su cantidad.

Aplica la regla 3 y registra un movimiento con `tipo = ajuste` y `documentable` en `null`.

### Sugerencia de reposición y generación de órdenes de compra

Un artículo está **por pedir** cuando:

```
(minimo > 0 AND existencia <= minimo) OR faltante_pendiente > 0
```

La **cantidad sugerida** es:

```
techo = maximo ?? minimo
sugerida = max(techo − existencia, 0) + faltante_pendiente
```

Ejemplo: mínimo 5, máximo 20, existencia 3, faltante 0 → sugiere 17. Con faltante 4 → sugiere 21,
porque además de rellenar hay que cubrir el descuadre.

`POST /api/v1/inventario/generar-ordenes-compra` toma **todos** los artículos por pedir del usuario,
los agrupa **por proveedor** (vía `articulo.catalogo.proveedor`) y crea **una orden de compra en
`borrador` por proveedor**, con una línea por artículo y la cantidad sugerida.

- Las líneas se precargan igual que cualquier orden de 012: `precio_unitario` con
  `costo_con_descuento`, `descripcion` y `modelo` copiados del artículo, `tasa_iva` la del artículo.
- Los totales se calculan en backend con `FacturaTotalesCalculator`, sin excepción.
- Cada orden toma su folio por usuario, como cualquier otra.
- Los artículos cuyo catálogo o proveedor está borrado lógicamente **se omiten**, y la respuesta los
  lista aparte para que el usuario sepa qué quedó fuera y por qué.
- **No se envía nada al proveedor.** Quedan en `borrador`, para revisar, corregir y enviar a mano.

La respuesta devuelve las órdenes creadas y los artículos omitidos.

### Totales del inventario

Cuatro cifras, calculadas **sobre el conjunto filtrado completo**, nunca sobre la página visible:

- **Unidades totales**: `SUM(existencia)`.
- **Dinero invertido**: `SUM(existencia × (costo_con_descuento + costo_goma))`.
- **Beneficio potencial**: `SUM(existencia × (precio_unitario_sin_iva − costo_con_descuento − costo_goma))`.
- **Artículos por pedir**: conteo de los que cumplen la condición de arriba.

Si se sumaran los 15 registros de la página, el "dinero invertido" cambiaría al pasar de página: sería
un número bonito y falso. Por eso los totales son una consulta agregada aparte, resuelta en la base
de datos, y viajan en `meta.totales` de la misma respuesta del listado.

El **ordenamiento** por dinero invertido o beneficio potencial se traduce a la misma aritmética dentro
del `ORDER BY`, porque `costo_total` y `utilidad` son atributos calculados y no columnas. Es el
camino que ya tomaron [011](011-precio-proveedor-utilidad.md) y
[014](014-costo-elaboracion-goma.md) para ordenar por costo y utilidad, así que no es terreno nuevo.

### Auditoría de existencias

`GET /api/v1/inventario/auditoria` recorre el historial de cada artículo en orden y reaplica las tres
reglas desde cero, comparando el resultado contra las columnas guardadas. Devuelve la lista de
artículos donde no coinciden, con ambos valores.

**Solo reporta; no corrige nada.** Si aparece un descuadre, se corrige con un ajuste manual, que
queda registrado como tal. Una reparación silenciosa borraría la evidencia de que algo estuvo mal.

No es una pantalla de uso diario: es la red de seguridad para el día que se sospeche de un número.

### Atomicidad y bloqueo

Dos protecciones, ambas dentro de la misma transacción:

**Idempotencia.** Cada operación verifica el estado de origen **dentro** de la transacción, no antes:
recibir exige que la orden siga en `pagada`, timbrar que la factura siga sin timbrar, entregar que la
cotización no esté ya en `producto_entregado`. El segundo intento —doble clic, `F5` a media
operación, reintento de red— encuentra el estado ya cambiado y **no hace nada**, sin error visible
para el usuario. Sin esto, un doble clic en "Recibir" sumaría la mercancía dos veces y además
saldaría faltantes que sí existían.

**Bloqueo de fila.** El artículo se bloquea (`lockForUpdate`) antes de leer sus contadores y hasta
terminar de escribirlos. Sin eso, dos salidas simultáneas del mismo artículo leen ambas `12`,
calculan `7` y `8`, y la segunda pisa a la primera: salieron nueve piezas y el sistema registró
cuatro. El riesgo real hoy es bajo —el inventario es por usuario y la idempotencia ya cubre el doble
clic—, pero el costo es una línea dentro de una transacción que de todos modos existe.

**El marcador y el historial se escriben siempre juntos.** Las columnas del artículo y el
`MovimientoInventario` se guardan en la misma transacción: si una falla, no se hace ninguna. Nunca
puede quedar una existencia que el historial no explique.

### Endpoints (bajo `auth:sanctum`, scopeados al usuario autenticado)

- `GET /api/v1/inventario` — listado paginado (15 por página, como
  [ArticuloController](../backend/app/Http/Controllers/ArticuloController.php)). Filtros combinables:
  `?q=` (nombre o modelo), `?catalogo=`, `?proveedor=`, `?por_pedir=1`, `?ver_todos=1`. Orden por
  `?orden=` (`nombre`, `modelo`, `existencia`, `invertido`, `beneficio`) y `?dir=`. Respuesta con
  `meta.totales`.
  Por defecto (`ver_todos` ausente) devuelve solo artículos con `existencia > 0`,
  `faltante_pendiente > 0` o `minimo > 0`.
- `PUT /api/v1/inventario/{articulo}/parametros` — body `{ minimo, maximo? }`. No genera movimiento:
  cambiar un umbral no mueve piezas.
- `POST /api/v1/inventario/{articulo}/ajuste` — ver "Ajustes manuales".
- `GET /api/v1/inventario/{articulo}/movimientos` — historial paginado, más reciente primero.
- `POST /api/v1/inventario/generar-ordenes-compra` — ver arriba.
- `GET /api/v1/inventario/auditoria` — ver arriba.

Las rutas estáticas (`generar-ordenes-compra`, `auditoria`) se declaran **antes** del binding
`{articulo}`, o Laravel las captura como si fueran un artículo — mismo cuidado que ya se tomó con
`articulos/exportar-csv` en `routes/api.php`.

Respuestas mediante API Resources (`InventarioResource`, `MovimientoInventarioResource`), consistente
con la convención de 001/004/005/006/007/008/009/010/012.

### Validaciones (Form Requests)

- `cantidad` (ajuste): requerida, entero, `min:0`. Cero es válido: "no me queda ninguno".
- `motivo` (ajuste): requerido, dentro de la lista manual; los motivos automáticos se rechazan.
- `nota`: opcional, string, `max:500`.
- `minimo`: requerido, entero, `min:0`. `maximo`: opcional, entero, `min:0`, `gte:minimo`.
- `{articulo}` debe pertenecer al usuario autenticado en los tres endpoints por artículo (`404` si
  no, no `403`, mismo criterio que el resto del sistema).
- `cotizacion_id` (factura): **sin cambios**. `StoreFacturaRequest` ya lo valida como opcional, del
  usuario y sin `factura_id` previo — es decir, ya garantiza que una cotización se factura una sola
  vez.

### Tests

Feature tests sobre la base MySQL de trabajo mediante `php artisan test` (nunca `migrate:fresh`, ver
[[feedback_nunca_migrate_fresh_en_dev]]):

1. Recibir una orden pagada suma las cantidades de sus líneas a la existencia de cada artículo.
2. Recibir dos veces la misma orden suma una sola vez.
3. Las líneas sin `articulo_id` se ignoran al recibir.
4. Dos líneas del mismo artículo en una orden suman su total y generan **un** movimiento.
5. Recibir con faltante pendiente salda primero el faltante y luego sube la existencia.
6. Timbrar una factura sin cotización vinculada descuenta; con cotización vinculada, no.
7. Marcar una cotización como entregada descuenta.
8. Vender más de lo disponible deja existencia en 0 y acumula el resto en faltante, sin fallar.
9. Cancelar una factura sin cotización vinculada devuelve las piezas saldando faltante primero.
10. Cancelar una factura con cotización vinculada no devuelve nada.
11. Un ajuste manual fija la cantidad final y pone el faltante en 0.
12. Un ajuste con motivo automático se rechaza con `422`.
13. La condición de "por pedir" y la cantidad sugerida, incluyendo el caso con faltante.
14. Generar órdenes de compra crea una orden en `borrador` por proveedor, con totales correctos.
15. Los artículos de catálogos o proveedores borrados se omiten y se reportan.
16. Los totales del listado corresponden al conjunto filtrado completo, no a la página.
17. La auditoría detecta un descuadre introducido a mano y no lo corrige.
18. Todos los endpoints devuelven `404` para artículos de otro usuario.

## Frontend (Vue 3)

### Pantallas

- **`/existencias`** (`ExistenciasListView.vue`, protegida): la pantalla principal del módulo.
  - **Cuatro tarjetas de totales** arriba: unidades, dinero invertido, beneficio potencial y
    artículos por pedir. Se recalculan con los filtros aplicados; filtrar por un proveedor muestra el
    dinero invertido en ese proveedor.
  - **Tabla**: artículo · modelo · catálogo · existencia · faltante · mínimo · máximo · invertido ·
    beneficio, ordenable por las columnas numéricas. Los renglones por pedir se destacan
    visualmente; el faltante se muestra solo cuando es mayor a cero.
  - **Filtros**: búsqueda por nombre o modelo, catálogo, proveedor, switch "Solo por pedir" y switch
    **"Ver todos"** (apagado por defecto, para no mostrar el catálogo entero en ceros).
  - **Acciones por renglón**: "Ajustar" (diálogo con cantidad final, motivo y nota), "Mínimo/máximo"
    (diálogo con los dos umbrales) y "Ver movimientos".
  - **Acción de pantalla**: "Agregar artículo al inventario" — buscador de artículos, que abre el
    mismo diálogo de ajuste; y **"Generar órdenes de compra"**, que muestra antes un resumen de qué
    se va a crear y para qué proveedores, y al confirmar lleva al listado de órdenes de compra con
    los borradores recién creados.
- **`/existencias/{id}/movimientos`** (`ExistenciaMovimientosView.vue`): historial paginado del
  artículo — fecha, tipo, motivo, cantidad, existencia y faltante resultantes, nota, y enlace al
  documento origen cuando lo hay. Solo lectura.

Los diálogos usan el `Dialog` del design system de [003](003-design-system-tailwind.md), igual que el
diálogo de artículo duplicado de 008.

### Cambios en pantallas existentes

- **Formulario de factura** (`FacturaFormView.vue`): el vínculo con la cotización ya llega por la
  URL desde el detalle de cotización, así que **no se agrega ningún campo**. Lo que se agrega es un
  aviso visible cuando la factura viene de una cotización: *"El inventario se descontará al marcar
  la cotización como entregada, no al timbrar esta factura."* Sin ese aviso la regla es invisible y
  parece un error.
- **Detalle de orden de compra** (`OrdenCompraDetalleView.vue`): el botón "Marcar como recibida"
  advierte en su confirmación que la mercancía entrará al inventario y que la acción no se puede
  deshacer.
- **Formulario de artículo** (`ArticuloFormView.vue`): **sin cambios**. Existencia, faltante, mínimo
  y máximo no se editan ahí. Artículos son datos maestros; Existencias es la operación diaria.

### Navegación

Se agrega **Existencias** al grupo **Inventario** de [013](013-navegacion-principal.md), que queda:
Artículos · Catálogos · **Existencias**.

La entrada se llama "Existencias" y no "Inventario" a propósito: el grupo del menú ya se llama
Inventario, y *Inventario → Inventario* no le dice nada a nadie.

## Fuera de alcance

- **Almacenes o ubicaciones múltiples**, traspasos entre ellos, y mercancía "en tránsito".
- **Números de serie, lotes y caducidades**: diez piezas de un artículo son diez piezas
  indistinguibles.
- **Recepción parcial** de una orden de compra, línea por línea, o con cantidades editables al
  recibir. La recepción es total y se corrige después con un ajuste.
- **Deshacer una recepción**. Se corrige con un ajuste manual.
- **Costo promedio ponderado, PEPS, o costo histórico por entrada.** Ver "Valuación al costo de hoy".
- **Inventario de insumos de goma** (polímero, negativos, tinta, mangos), explícitamente fuera desde
  [014](014-costo-elaboracion-goma.md).
- **Alertas** por correo, WhatsApp o notificación por artículos bajo mínimo: la señal es visual,
  dentro de la pantalla de Existencias.
- **Sugerencia automática del mínimo** a partir del historial de ventas. El mínimo se captura a mano.
- **Envío automático** de las órdenes de compra generadas: nacen en `borrador`.
- **Bloqueo del borrado de un artículo** con existencia. Sigue siendo borrado lógico; deja de contar
  en los totales y su historial se conserva.
- **Efecto en Tesorería.** El dinero salió al pagar la orden; recibir mercancía no genera ningún
  movimiento de dinero.
- **Exportación** del inventario a Excel o PDF.
- **Validación `422` de artículo duplicado en el backend** de factura, cotización y orden de compra.
  El hueco existe (ver [008](008-cotizaciones.md), adición 31) y esta spec lo cubre defensivamente
  agrupando, pero cerrarlo cambia el contrato de tres endpoints y merece spec propia.

## Estado de implementación

Implementada el **2026-08-10**. `php artisan test` corre limpio (349 tests, 31 de ellos nuevos en
`tests/Feature/InventarioTest.php`); ESLint, Prettier y Vitest también.
**No se pudo verificar visualmente en un navegador real** (limitación de entorno): se recomienda
abrir `/existencias`, recibir una orden pagada y confirmar los cuatro totales y el historial.

La versión original de esta sección afirmaba además que `vue-tsc` y `vite build` corrían limpios.
No era cierto — ver la corrección de abajo.

### Dos correcciones sobre lo que esta spec afirmaba

- **El vínculo factura → cotización ya existía**, en sentido inverso: `cotizaciones.factura_id`, que
  el flujo "Facturar" del detalle de cotización escribe desde 008, y `Factura::cotizacion()` ya
  estaba declarado como `hasOne`. La versión original de esta spec proponía una columna
  `facturas.cotizacion_id` nueva; se descartó al encontrarlo, porque habría dejado dos fuentes de
  verdad contradictorias. La migración correspondiente se eliminó antes de aplicarse.
- **El orden de escritura de ese vínculo estaba mal para inventario.** `FacturaController::store`
  lo escribía *después* de `intentarTimbrar()`, y el timbrado es justo el momento en que se decide
  si descontar existencias: toda factura nacida de una cotización se habría visto como venta de
  mostrador y habría descontado mercancía que la cotización descuenta otra vez al entregarse. Se
  movió dentro de la transacción de creación, antes de timbrar.

### Detalles resueltos durante la implementación

- **La devolución por cancelación se ata al estado `accepted`**, no a la solicitud de cancelación.
  El SAT puede dejarla `pending`, y mientras tanto la factura sigue vigente. El mismo control corre
  en `refrescarEstadoCancelacion()`, con un guardia que impide devolver dos veces.
- **Los alias de los agregados llevan prefijo `suma_`.** `Articulo` tiene accesores llamados
  `dinero_invertido` y `beneficio_potencial`; un alias SQL con ese nombre queda eclipsado por el
  accesor, que recalcula sobre atributos no seleccionados y devuelve 0. Costó un test en rojo.
- **La línea generada usa la tasa general de IVA.** El artículo no guarda una tasa propia, así que
  la línea nace en `16` —igual que cuando se captura a mano en `DocumentoLineas`— y es editable en
  el borrador.
- **La recepción ahora se confirma.** Antes era un clic directo; al volverse irreversible *y*
  mover existencias, el botón abre un diálogo que lo advierte.

### Corrección del 2026-08-11: los campos numéricos vacíos de `/existencias`

`npm run build` no compilaba. Los diálogos de ajuste de existencia y de umbrales ataban
`cantidadAjuste` y `maximo` —ambos `ref<number | null>`, porque un campo sin capturar no tiene
valor— a `<Input>` con `v-model.number`, y el componente declara `modelValue?: string | number`.
`vue-tsc` lo rechazaba con dos `TS2322`, así que no se generaba `dist/`.

Se corrigió en `ExistenciasListView.vue` adoptando el patrón que el proyecto ya usaba en el
diálogo de pago de `CotizacionDetalleView.vue` y en el de complemento de `FacturaDetalleView.vue`:
la coerción se hace en el sitio de uso, con `:model-value="x ?? undefined"` y un
`@update:model-value` que traduce la cadena vacía de vuelta a `null`. `minimo` no necesitó cambio:
es `ref<number>(0)`, nunca nulo.

La alternativa —ampliar `Input.vue` a `string | number | null`— se descartó: es un componente
generado por shadcn, y divergirlo significa perder el cambio en la siguiente regeneración a cambio
de nada que el patrón existente no resuelva ya.

El defecto se coló porque `npm run dev` no hace comprobación de tipos y `npm run build` sí
(`vue-tsc -b && vite build`). Se detectó al preparar el despliegue (ver
`018-despliegue-hostinger.md`), que es el primer momento en que el build de producción se corrió
de verdad.

## Criterios de aceptación

1. La pantalla `/existencias` lista los artículos con existencia, faltante, mínimo, máximo, dinero
   invertido y beneficio potencial, y muestra los cuatro totales del conjunto filtrado.
2. Al abrirla sin filtros solo aparecen artículos con existencia, con faltante o con mínimo
   configurado; el switch "Ver todos" muestra el catálogo completo.
3. Los totales cambian al aplicar un filtro y **no** cambian al pasar de página.
4. Ordenar por dinero invertido ordena todos los artículos del conjunto filtrado, no solo los quince
   visibles.
5. Marcar como recibida una orden de compra pagada suma las cantidades de sus líneas al inventario y
   deja un movimiento por artículo, con enlace a la orden.
6. Volver a ejecutar la recepción de una orden ya recibida no vuelve a sumar.
7. Una orden con dos líneas del mismo artículo suma la cantidad total y deja un solo movimiento.
8. Timbrar una factura sin cotización vinculada descuenta las piezas; si la existencia no alcanza, la
   factura se timbra igual, la existencia queda en 0 y el resto se registra como faltante pendiente.
9. Timbrar una factura con cotización vinculada no mueve el inventario; el descuento ocurre al marcar
   esa cotización como "producto entregado".
10. Cancelar una factura sin cotización vinculada devuelve las piezas, saldando primero el faltante
    pendiente; cancelar una factura con cotización vinculada no devuelve nada.
11. Un ajuste manual captura la cantidad final, exige motivo, deja el faltante en cero y queda en el
    historial con su motivo y su nota.
12. Agregar al inventario un artículo que nunca tuvo existencia se hace desde el buscador de la
    pantalla y usa el mismo diálogo de ajuste.
13. Un artículo aparece como "por pedir" cuando su existencia es menor o igual a su mínimo (con
    mínimo mayor a cero) o cuando tiene faltante pendiente.
14. La cantidad sugerida es (máximo − existencia) + faltante pendiente, sin bajar de cero.
15. "Generar órdenes de compra" crea una orden en `borrador` por proveedor con los artículos por
    pedir y sus cantidades sugeridas, sin enviar nada, y reporta los artículos omitidos por tener el
    catálogo o el proveedor borrado.
16. El historial de un artículo muestra cada movimiento con su tipo, motivo, cantidad, existencia y
    faltante resultantes, y enlace al documento origen cuando aplica; no se puede editar ni borrar.
17. La auditoría reporta cualquier artículo cuya existencia guardada no coincida con la reconstruida
    desde su historial, y no la modifica.
18. Ningún endpoint del módulo permite ver ni mover artículos de otro usuario.

## Supuestos asumidos (registro completo)

1. La unidad que se cuenta es el **Artículo**, no el catálogo ni el modelo como texto. Dos artículos
   de catálogos distintos con el mismo modelo son existencias separadas y no se suman.
2. La existencia, el faltante, el mínimo y el máximo son **columnas de `articulos`**, no una tabla
   aparte. Se eligió por costo: la lista de inventario es la lista de artículos con cuatro columnas
   más, sin cruces ni renglones que aparezcan y desaparezcan.
3. Todos los artículos forman parte del inventario desde el día uno, con existencia `0`. No hay que
   "dar de alta" un artículo para que exista; el filtro por defecto resuelve el ruido visual.
4. La existencia **nunca es negativa**: toca fondo en `0` y el excedente se acumula como faltante
   pendiente.
5. Un faltante pendiente es un **descuadre de registro**, no una deuda con un cliente: la mercancía
   ya salió físicamente.
6. Toda entrada que suma una cantidad —recepción y devolución por cancelación— **salda primero el
   faltante**. Es una sola regla para todo el sistema.
7. Todo ajuste manual **fija** la cantidad final y **pone el faltante en cero**. El alta manual es
   ese mismo ajuste con punto de partida en cero, por eso también fija en lugar de sumar.
8. El inventario se valúa con el **costo actual** del artículo; cambiar precios revalúa el inventario
   completo. No hay costo promedio, PEPS ni costo histórico por entrada.
9. Todas las cifras del módulo son **sin IVA**, en pesos mexicanos.
10. El beneficio potencial usa el precio de lista del artículo, sin descuentos de cliente (015).
11. El mínimo se captura **a mano** por artículo; el sistema no lo deduce del historial. Un mínimo en
    `0` significa "no me avises de este artículo".
12. El máximo es opcional; si no se captura, el techo de la sugerencia es el propio mínimo.
13. El sistema **sugiere** cuánto pedir y **arma borradores** de orden de compra agrupados por
    proveedor, pero nunca envía nada al proveedor por su cuenta.
14. Las ventas descuentan inventario: la factura al timbrarse, la cotización al marcarse
    `producto_entregado`. Ambos caminos existen en el negocio y ninguno cubre al otro.
15. Si una factura tiene cotización vinculada, **la cotización manda siempre**: esa factura nunca
    descuenta ni devuelve, aunque la cotización aún no se haya entregado. Facturar antes de entregar
    no mueve inventario porque la mercancía sigue en bodega.
16. El vínculo es el `cotizaciones.factura_id` que 008 ya escribe; no se crea una columna nueva en
    `facturas`, para no tener dos fuentes de verdad. La única corrección al flujo existente es
    escribirlo **antes** de timbrar, porque el timbrado es el momento en que se decide si descontar.
17. Una cotización se factura una sola vez; `StoreFacturaRequest` ya lo garantiza desde 008.
18. La cancelación devuelve piezas solo cuando el SAT la **acepta**; una cancelación `pending` deja
    la mercancía fuera, porque la factura sigue vigente.
19. Una salida **nunca bloquea** la venta. El inventario arranca en cero y bloquear timbrados hasta
    terminar de cargar existencias haría el sistema inusable.
20. La recepción de una orden es **total, irreversible y una sola vez**; se corrige con un ajuste.
21. Las líneas de documento sin `articulo_id` se ignoran en todos los movimientos.
22. Las líneas del mismo artículo dentro de un documento se **agrupan y suman** antes de mover
    existencias, como red defensiva ante el hueco de validación del backend.
23. No hay carga retroactiva: las órdenes ya `recibida` antes de esta historia no suman nada.
24. El historial es **solo de consulta**; los errores se corrigen con movimientos nuevos, no
    borrando los viejos.
25. La existencia se guarda como número (no se recalcula en cada consulta) y se escribe **siempre en
    la misma transacción** que su movimiento, con el artículo bloqueado. La auditoría es la red de
    seguridad, y solo reporta.
26. Los totales y el ordenamiento se resuelven sobre el **conjunto filtrado completo** en la base de
    datos, nunca sobre la página visible.
27. Un solo almacén; sin series, lotes ni caducidades.
28. El inventario es **por usuario**, mismo patrón que 004/005/006/007/008/009/010/012.
29. El inventario **no toca Tesorería**.
30. El módulo vive en una pantalla propia llamada **Existencias**, dentro del grupo Inventario del
    menú. Artículos sigue siendo datos maestros y no gana columnas de existencia.
31. Borrar un artículo no se bloquea por tener existencia: deja de contar en los totales y su
    historial se conserva.
32. Esta spec **supera** la decisión de 012 de no llevar inventario; la 012 se anota en consecuencia.
