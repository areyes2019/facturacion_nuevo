# Spec: Orden de captura y filtros por columna en el listado de artículos

## Historia de usuario

Como usuario que acaba de subir un CSV de doscientos artículos, quiero ver esos artículos juntos y
en el mismo orden que traía mi hoja de cálculo —sin tener que pedirlo, sin ordenar nada a mano— y
quiero poder acotar la tabla por columna —este modelo, este catálogo, de tal precio a tal precio—
para revisar lo que cargué sin recorrer catorce páginas.

## Objetivo / Alcance

Dos cosas sobre el listado de `/articulos`, que se resuelven juntas porque tocan exactamente el
mismo código —`filtrarBusqueda` y `ordenar` en `ArticuloController`, y el estado de la tabla en
`stores/articulos.ts`— y comparten pantalla:

1. **El listado sale siempre en orden de captura**: el orden en que los artículos entraron al
   sistema, que para una importación es el orden de las filas del archivo. No es una opción que se
   elige; es como sale la tabla.
2. **Un filtro por columna en cada cabecera de la tabla**: texto en Nombre, Modelo y Catálogo; rango
   desde–hasta en Costo, Precio e Utilidad.

**El buscador global de arriba se queda.** Sigue siendo la vía rápida para una palabra suelta y los
filtros por columna se combinan con él, no lo sustituyen.

Todo el filtrado y toda la ordenación ocurren **en el backend**. No hay ningún filtrado en el
navegador.

### El problema que resuelve

#### El orden no es el de la hoja

Al terminar una carga masiva, los artículos aparecen "revueltos" respecto del Excel. No lo están: la
importación crea las filas en el orden del archivo, una por una, y quedan en secuencia. Lo que
reordena es el listado cuando ordena alfabéticamente sobre **todo** el catálogo, no sobre lo recién
importado.

Combinado con la paginación de 15, el efecto es peor que un simple reordenamiento: los doscientos
artículos nuevos quedan repartidos entre las páginas de los que ya estaban, intercalados con ellos.
Revisar lo que acaba de entrar exige recorrer el listado completo.

**El orden de captura se necesita por omisión, no bajo petición.** Un orden que hay que pedir cada
vez que se entra a la pantalla no resuelve "quiero ver lo que subí": obliga a recordar que existe, a
saber cuál es la columna correcta y a hacer un clic antes de poder trabajar. La tabla sale en el
orden en que se cargaron los artículos y ya.

#### Un solo buscador no alcanza

El buscador global es una caja de texto que pega contra nombre, modelo y proveedor a la vez. Sirve
para "búscame el Printer 38" y no sirve para nada más:

- No distingue columna. Un término que aparece en el nombre de un artículo y en el nombre comercial
  de un proveedor devuelve las dos cosas mezcladas.
- **No alcanza a las columnas de dinero.** No hay ninguna manera de preguntar "qué tengo entre 500 y
  800 pesos", que es justamente la pregunta con la que se revisan precios. Ordenar por precio ayuda
  a medias: hay que localizar a ojo dónde empieza el rango y dónde termina, saltando páginas.
- No acota por catálogo, aunque el listado ya sabe a qué catálogo pertenece cada artículo y lo
  muestra en su propia columna.

## Backend (Laravel)

### El orden de captura es el orden base

`ordenar()` **cae a `orderBy('id')` ascendente** cuando no se pide otra cosa, y el desempate de las
ordenaciones que sí se piden también es por `id`.

`ORDENACIONES` contiene únicamente las tres columnas de dinero —`costo_total`,
`precio_unitario_sin_iva` y `utilidad`—, que son las que tienen control en la cabecera. **No hay una
clave `id`**: ordenar por captura no es algo que el cliente pida, sino lo que el servidor hace por
su cuenta cuando nadie pide nada. Dos caminos para llegar al mismo orden serían dos caminos que
mantener de acuerdo.

**El orden alfabético por nombre desaparece del listado**, tanto por omisión como bajo petición. Para
llegar a un artículo por su nombre están el buscador global y el filtro de la columna Nombre, que es
lo que realmente se usa: nadie recorre un catálogo alfabético de mil renglones buscando una "P".

El desempate por `id` en lugar de por `nombre` mantiene una sola idea de orden en toda la tabla: dos
artículos del mismo precio salen en el orden en que se cargaron, igual que salen cuando no hay
ninguna ordenación pedida.

### El id no se expone

`ArticuloResource` sigue enviando el `id` porque es lo que identifica al artículo para editarlo,
borrarlo y marcarlo con la casilla de selección. **Lo que no existe es ninguna forma de verlo ni de
usarlo como criterio**: ni columna en la tabla, ni control de ordenación, ni filtro. Un filtro de
"búscame el artículo número 47" con el número escondido en toda la aplicación no lo puede usar
nadie, porque no hay dónde leer el 47.

### Los filtros

Todos viven en `filtrarBusqueda`, que ya recibe la `Request` y ya es el único punto por el que pasan
tanto el listado como la exportación CSV. Cada filtro es un `when()` más, y **todos se combinan con
Y** entre sí y con el `search` global y el `proveedor_id` que ya existían.

| Parámetro | Columna | Comportamiento |
| --- | --- | --- |
| `filtro_nombre` | `nombre` | `LIKE %valor%` |
| `filtro_modelo` | `modelo` | `LIKE %valor%` |
| `filtro_catalogo_id` | `catalogo_id` | Igualdad exacta contra el catálogo del artículo. |
| `costo_min` / `costo_max` | costo total | Rango cerrado sobre `costo_con_descuento + costo_goma`. |
| `precio_min` / `precio_max` | `precio_unitario_sin_iva` | Rango cerrado. |
| `utilidad_min` / `utilidad_max` | utilidad | Rango cerrado sobre `precio_unitario_sin_iva - (costo_con_descuento + costo_goma)`. |

Los nombres llevan prefijo `filtro_` en las columnas de texto para no chocar con `search`, y las de
rango llevan sufijo `_min`/`_max` porque el par es el filtro: no son dos filtros distintos.

**Cada extremo de un rango es independiente.** Mandar solo `precio_min` significa "de ahí para
arriba", solo `precio_max` significa "de ahí para abajo", y los dos juntos delimitan. Es lo que hace
que el filtro sea usable escribiendo un solo número, que es como se usa la mitad de las veces.

**Un extremo vacío, no numérico o negativo se ignora en silencio**, igual que se ignora un `sort` que
no se reconoce. Un filtro a medio escribir no debe producir un error en pantalla ni vaciar la tabla:
mientras el usuario teclea "1", "12", "125", los tres estados son legítimos.

**Un rango invertido —mínimo mayor que máximo— devuelve cero resultados** y no se corrige ni se
advierte. Es lo que literalmente se pidió, la tabla vacía lo comunica de inmediato, y adivinar que
el usuario quiso decir lo contrario sería peor que obedecerle.

#### Costo y utilidad no son columnas de la tabla

`costo_total` y `utilidad` **no están persistidos**: se calculan. Sus filtros de rango van con
`whereRaw` sobre **exactamente las mismas expresiones** que ya usa `ORDENACIONES` para ordenarlos
([014](014-costo-elaboracion-goma.md)). Las expresiones se declaran una sola vez y las consumen
ordenación y filtro, para que no puedan divergir: una tabla donde ordenar por costo y filtrar por
costo entendieran cosas distintas sería indefendible.

### La exportación CSV hereda todo

`exportarCsv` ya reusa `filtrarBusqueda` y `ordenar`, así que **exporta exactamente lo que la tabla
está mostrando, en el mismo orden**, sin una línea de código nueva. Es la propiedad que hace que los
filtros valgan más de lo que cuestan: acotar en pantalla y bajarse ese subconjunto ya es un flujo
completo.

El archivo conserva sus ocho columnas de siempre —`nombre`, `modelo`, `clave_prod_serv`,
`clave_unidad`, `objeto_imp`, `precio_proveedor`, `utilidad_porcentaje`, `tamano_goma`— para seguir
siendo reimportable ([011](011-precio-proveedor-utilidad.md)). El `id` nunca ha viajado en él y sigue
sin hacerlo.

## Frontend (Vue 3)

### La tabla no muestra el id

Ocho columnas: la casilla de selección, Nombre, Modelo, Catálogo, Costo, Precio, Utilidad y
Acciones. **El número interno del artículo no se dibuja en ninguna parte** —ni en el listado, ni en
la ficha, ni en el formulario de edición— y no hay preferencia ni interruptor que lo devuelva. Un
dato que no le sirve al usuario para nada ocupa ancho de pantalla y le hace creer que significa
algo.

**Nombre se queda con el ancho que la columna de números liberó.** Es la única columna sin ancho
declarado y la que más se recortaba con elipsis, así que es donde ese espacio se nota.

Costo, Precio e Utilidad conservan su control de ordenación en la cabecera; quitarlo devuelve la
tabla al orden de captura. `ArticuloSort` cubre solo esas tres.

### La fila de filtros

Debajo de la fila de cabeceras, dentro del mismo `<TableHeader>`, va **una segunda fila con un
control por columna**:

```
[ ] │ Nombre    │ Modelo    │ Catálogo   │ Costo    │ Precio   │ Utilidad │ Acciones
    │ [contiene]│ [contiene]│ [Todos  ▾] │ [min–max]│ [min–max]│ [min–max]│
```

- **Nombre** y **Modelo**: campos de texto, "contiene", sin distinguir mayúsculas.
- **Catálogo**: un `<select>` con "Todos los catálogos" como opción por defecto, alimentado por el
  listado de catálogos que ya consume `CatalogoSelect.vue`. Es un selector y no texto libre porque
  los catálogos son un conjunto cerrado y corto, y escribir el nombre a mano solo abre la puerta a
  no encontrar nada por una tilde.
- **Costo**, **Precio**, **Utilidad**: dos campos numéricos pequeños, mínimo y máximo.
- Las columnas de selección y de acciones no llevan filtro.

**Los filtros no se ocultan detrás de un menú por columna.** Están siempre visibles en su fila. Un
filtro escondido tras un ícono obliga a abrir cinco menús para saber qué está aplicado, y el
principal riesgo de esta historia es exactamente ese: no entender por qué la tabla muestra menos
renglones de los que debería.

### Cuándo se dispara la consulta

- Los campos de texto y numéricos reutilizan el **rebote de 300 ms** que ya tiene el buscador global,
  para no lanzar una petición por tecla.
- El selector de catálogo consulta de inmediato: es una elección, no algo que se escribe.
- **Cualquier cambio de filtro vuelve a la página 1.** Filtrar y quedarse en la página 7 de un
  resultado que ahora tiene 2 páginas deja la tabla vacía por una razón que no es la que el usuario
  cree.
- La selección múltiple se vacía al cambiar las filas, como ya ocurre hoy: el `watch` sobre
  `articulos.items` cubre el caso sin tocarlo ([021](021-mantenimiento-articulos-catalogos.md)).

### Filtros activos y cómo quitarlos

Cuando hay al menos un filtro de columna aplicado, encima de la tabla aparece una línea con el
conteo de resultados y un botón **"Limpiar filtros"** que borra todos los de columna de un golpe.

**El buscador global no se limpia con ese botón** y tiene el suyo propio: son dos cosas que el
usuario puso por separado y en momentos distintos, y borrar de más es tan molesto como no poder
borrar.

### Layout

Ocho columnas más una fila de controles es lo más ancho que ha estado esta tabla, y no cabe en el
ancho de lectura con el que se presenta el resto del sistema. **La pantalla de artículos se muestra
en el contenedor amplio** de [003](003-design-system-tailwind.md): el ancho de lectura está pensado
para formularios y prosa, y aplicárselo a un listado denso no es una decisión de estética sino la
diferencia entre ver la tabla completa y tener que arrastrar una barra.

**En escritorio la tabla no lleva barra de desplazamiento horizontal.** Ninguna. Una barra dentro de
la tabla es la peor forma de esconder algo: la columna de acciones queda fuera de la vista y nada en
pantalla dice que está ahí, que es exactamente el desborde que hubo que corregir en
[006](006-gestion-articulos.md) el 2026-08-03.

Para que eso se sostenga sin depender de qué tan largo sea el contenido:

- **La tabla es de ancho fijo** (`table-fixed`): los anchos los mandan las clases de la fila de
  cabeceras y ningún dato puede ensanchar su columna. Sin eso, un nombre de artículo largo empuja al
  resto y el desborde vuelve por donde vino.
- **Nombre es la única columna sin ancho declarado**: se queda con lo que sobre, que es donde mejor
  se aprovecha. Se recorta con elipsis y expone el texto completo en el `title`, igual que Modelo y
  Catálogo.
- **Los campos de rango van con espacio suficiente para escribir un importe**, no apretados hasta
  que solo quepan tres dígitos. Son campos de texto y no `<input type="number">`: las flechitas del
  control nativo se comen un tercio de una celda tan angosta, y lo que no sea un número el backend ya
  lo ignora en silencio.
- **Los botones de acciones van en su propio contenedor dentro de la celda**, no en la celda misma:
  una celda de tabla en modo flex deja de respetar el ancho de su columna.

En móvil la tabla sí se desplaza dentro de su tarjeta, porque ocho columnas no caben en 375 px de
ninguna manera legible. Es la única excepción, y **el `<body>` no hace scroll horizontal nunca**: lo
que se desplaza es la tabla, jamás la página.

### El orden alcanza a todas las pantallas que leen la lista

El orden de captura no es una preferencia del listado de artículos: es el orden en que el sistema
entrega los artículos. Alcanza también al buscador de artículos de facturas, cotizaciones y órdenes
de compra ([007](007-facturacion.md), [008](008-cotizaciones.md), [012](012-ordenes-compra.md)) y al
catálogo del mostrador ([031](031-mostrador-consulta.md)), que consumen el mismo endpoint. No hay
nada que hacer para conseguirlo —heredan el orden por omisión— y tampoco hay nada que hacer para
evitarlo: un mismo catálogo que saliera en dos órdenes distintos según la pantalla sería más difícil
de explicar que cualquiera de los dos órdenes por separado.

## Fuera de alcance

- **Un orden alfabético opcional.** No queda ninguno: ni por omisión, ni como columna ordenable, ni
  como opción en un menú.
- **Mostrar el id en cualquier otro lugar** de la aplicación, o una preferencia para devolver la
  columna. Se va de la pantalla y no vuelve.
- **Invertir el orden de captura** para ver primero lo más reciente. La tabla sale de viejo a nuevo y
  no hay control para voltearla.
- **Llevar al usuario a la página del artículo que acaba de crear.** Un artículo nuevo cae al final
  del listado, en la última página, y encontrarlo es trabajo del buscador.
- **Filtros en otros listados** (clientes, proveedores, catálogos, facturas, cotizaciones, órdenes de
  compra). Esta historia toca únicamente `/articulos`. Si el patrón demuestra servir, extenderlo es
  otra historia con su propio spec.
- **Guardar o recordar los filtros** entre visitas a la pantalla, en `localStorage` o en la URL.
  Cada entrada al listado empieza sin filtros.
- **Filtros combinables con O**, negaciones ("que no contenga"), o comodines dentro del texto. Todo
  se combina con Y y el texto es siempre "contiene".
- **Rangos de fecha** por `created_at` o `updated_at`. El orden de captura ya cubre el caso real
  —"lo que acabo de subir"— sin agregar dos campos de calendario a la cabecera.
- **Filtrar por `clave_prod_serv`, `clave_unidad`, `objeto_imp`, `tamano_goma` o proveedor**, que no
  tienen columna propia en la tabla. Sin columna visible no hay dónde poner el filtro, y esta
  historia no agrega columnas nuevas.
- **Filtrar por precio con IVA.** La columna que la tabla muestra es la de sin IVA, y es sobre la
  que se filtra.
- **Seleccionar todos los resultados del filtro** para el borrado en lote. La selección sigue siendo
  de la página visible ([021](021-mantenimiento-articulos-catalogos.md)).
- **Un buscador global que entienda sintaxis** tipo `precio:>500`. Se filtra con controles, no con
  lenguaje.
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Criterios de aceptación

1. Al entrar a `/articulos` sin tocar nada, los artículos salen en el orden en que fueron creados,
   del más viejo al más nuevo, de modo que los de una importación CSV aparecen consecutivos y en el
   orden de las filas del archivo.
2. El listado no muestra en ninguna parte el número interno del artículo: no hay columna de id, ni
   control para ordenar por él, ni casilla para filtrarlo.
3. La tabla tiene ocho columnas: selección, Nombre, Modelo, Catálogo, Costo, Precio, Utilidad y
   Acciones.
4. Costo, Precio e Utilidad siguen siendo ordenables y alternan ascendente → descendente → sin
   ordenar; al quedar sin ordenar, la tabla vuelve al orden de captura.
5. Con una ordenación por Costo, Precio o Utilidad, los artículos con el mismo valor salen entre sí
   en orden de captura.
6. No existe forma de ver el listado en orden alfabético por nombre.
7. Debajo de las cabeceras hay una fila de filtros con: campo de texto en Nombre y en Modelo,
   selector con "Todos los catálogos" en Catálogo, y par mínimo–máximo en Costo, Precio e Utilidad.
   Las columnas de selección y acciones no llevan control.
8. Escribir en el filtro de Nombre acota el listado a los artículos cuyo nombre contiene ese texto,
   sin distinguir mayúsculas ni exigir que sea el principio del nombre. Lo mismo para Modelo.
9. Elegir un catálogo en su filtro deja solo los artículos de ese catálogo; volver a "Todos los
   catálogos" los devuelve.
10. Un rango con mínimo y máximo devuelve los artículos cuyo valor cae entre ambos, incluidos los
    extremos, en cada una de las tres columnas de dinero.
11. Un rango con solo mínimo devuelve todo lo que esté por encima; con solo máximo, todo lo que esté
    por debajo.
12. Un rango invertido (mínimo mayor que máximo) devuelve cero resultados, sin error en pantalla.
13. Un extremo de rango vacío, no numérico o negativo se ignora: la tabla se comporta como si ese
    extremo no se hubiera escrito, sin error y sin vaciarse.
14. Los filtros de Costo y de Utilidad operan sobre los mismos valores calculados que muestra la
    tabla y por los que se ordena: un artículo que la tabla muestra con costo de $120 aparece en el
    rango 100–150 y no en el 0–100.
15. Varios filtros a la vez se combinan con Y: modelo que contiene "Printer" **y** precio entre 500
    y 800 devuelve solo los que cumplen las dos condiciones.
16. Los filtros de columna se combinan con el buscador global, que sigue en su lugar y sigue pegando
    contra nombre, modelo y proveedor.
17. Los filtros se combinan con la ordenación y con el orden de captura: filtrar conserva el orden, y
    ordenar conserva el filtro.
18. Cambiar cualquier filtro devuelve la paginación a la página 1.
19. Escribir en un filtro de texto no lanza una petición por tecla: la consulta sale tras una pausa,
    como ya ocurre con el buscador global.
20. Con al menos un filtro de columna aplicado aparece un botón "Limpiar filtros" que los borra
    todos; el buscador global conserva su contenido.
21. Exportar CSV descarga exactamente los artículos que la tabla está mostrando, en el mismo orden,
    con las ocho columnas de siempre y sin ninguna columna de id.
22. La selección múltiple y el borrado en lote siguen funcionando sobre la página visible del
    resultado filtrado, y la selección se vacía cuando el filtro cambia las filas.
23. Editar un artículo no cambia su posición en el listado.
24. El buscador de artículos de facturas, cotizaciones y órdenes de compra y el catálogo del
    mostrador entregan los artículos en orden de captura, y `proveedor_id` sigue acotando el selector
    de artículos de Orden de compra ([012](012-ordenes-compra.md)).
25. En escritorio (≥1280px) la tabla con sus ocho columnas y la fila de filtros se ve completa **sin
    barra de desplazamiento horizontal**, con los dos botones de acciones enteros dentro de la vista
    y los campos de rango con espacio para escribir un importe de cinco cifras.
26. Un artículo con nombre largo (más de 60 caracteres) se recorta con elipsis y no ensancha su
    columna ni empuja a las demás; el nombre completo se ve en el `title`. Lo mismo con un catálogo
    de nombre largo.
27. En móvil la página no desborda horizontalmente: si algo se desplaza es la tabla dentro de su
    tarjeta, nunca el `<body>`.
28. Las demás pantallas conservan su ancho de lectura de siempre: solo el listado de artículos usa el
    contenedor amplio.
29. Pint corre sin errores sobre el código de backend modificado, ESLint y Prettier sobre el de
    frontend, la suite de Pest sigue pasando y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

1. Los artículos ya se crean en el orden del archivo importado; lo que hay que arreglar es la forma
   de ver ese orden, no la importación.
2. El orden de captura es el orden base del listado, no una opción que se pide.
3. "Orden de captura" es de lo más viejo a lo más nuevo: lo primero que se subió sale primero.
4. El orden alfabético por nombre desaparece: ni por omisión, ni como columna ordenable.
5. El número interno del artículo deja de mostrarse por completo, y no queda ninguna forma de volver
   a verlo.
6. Al desaparecer de la vista, desaparecen también su control de ordenación y su filtro exacto.
7. Costo, Precio e Utilidad siguen siendo ordenables; quitar la ordenación devuelve al orden de
   captura.
8. Los empates de una ordenación por dinero se desempatan por orden de captura.
9. El ancho que libera la columna de números se lo queda Nombre.
10. El orden alcanza a todas las pantallas que leen la lista de artículos, no solo a `/articulos`.
11. Ningún otro listado del sistema cambia de orden.
12. Un artículo nuevo cae al final del listado y nada lleva al usuario hasta ahí.
13. Editar un artículo no lo mueve de su posición.
14. El buscador global se queda y convive con los filtros de columna; no lo sustituyen.
15. Todos los filtros se combinan con Y, entre ellos y con el buscador global.
16. Los filtros de texto son "contiene", sin distinguir mayúsculas.
17. El filtro de catálogo es un selector de la lista existente, no texto libre.
18. Las columnas de dinero se filtran por rango desde–hasta, no por valor exacto: nadie busca un
    precio exacto, busca un tramo.
19. Cada extremo de un rango es opcional por separado.
20. Un filtro mal escrito o a medio escribir se ignora en silencio; nunca produce error en pantalla
    ni vacía la tabla por un motivo que el usuario no pidió.
21. Un rango invertido devuelve cero resultados y no se corrige solo.
22. Los filtros no se recuerdan entre visitas ni viajan en la URL: cada entrada empieza limpia.
23. "Limpiar filtros" borra los de columna y no el buscador global.
24. Los filtros están siempre visibles en su fila, no escondidos tras un menú por columna.
25. La exportación CSV respeta filtros y orden, porque exportar lo que se está viendo es el
    comportamiento esperado, y conserva sus ocho columnas reimportables.
26. La selección múltiple sigue siendo de la página visible, también con filtros aplicados.
27. Solo se filtra por las columnas que la tabla muestra.
28. Esta historia toca únicamente el listado de artículos; los demás listados del sistema quedan
    como están.
29. **(Adición técnica)** El número interno sigue viajando del servidor al navegador, porque es lo
    que identifica al artículo para editarlo, borrarlo y marcarlo. Lo que se quita es su
    presentación, no el dato.
30. **(Adición técnica)** El servidor deja de aceptar "ordéname por id" como petición: el orden de
    captura es lo que hace cuando no se le pide nada. Un solo camino en vez de dos que mantener de
    acuerdo.
31. **(Adición técnica)** El filtro por id exacto se quita también del servidor: con el número
    escondido en toda la aplicación, nadie puede saber cuál escribir.
32. **(Adición técnica)** Todo el filtrado y la ordenación ocurren en el servidor, nunca en el
    navegador: la tabla está paginada de 15 en 15, y un filtro que corriera sobre los datos ya
    descargados solo acotaría los quince renglones a la vista.
33. **(Adición técnica)** Costo total y utilidad no son columnas de la base de datos, sino
    expresiones calculadas. Sus filtros usan las mismas expresiones que ya usa la ordenación
    ([014](014-costo-elaboracion-goma.md)), declaradas en un solo lugar para que filtrar y ordenar
    no puedan entender cosas distintas.
34. **(Adición técnica)** Los parámetros de filtro son aditivos: una petición sin ellos responde el
    listado completo en orden de captura, así que lo que hoy consume el endpoint —incluido el
    `proveedor_id` de Orden de compra— sigue funcionando.
35. **(Adición técnica)** La exportación CSV no necesita código nuevo: ya reusa el mismo filtrado y
    la misma ordenación que el listado.
36. **(Adición técnica)** Los campos de texto y numéricos comparten el rebote de 300 ms que ya tiene
    el buscador global; el selector de catálogo consulta de inmediato.
37. **(Adición técnica)** El listado de artículos se muestra en el contenedor amplio de
    [003](003-design-system-tailwind.md), que ensancha a la vez la barra superior, el menú móvil y el
    contenido: ensanchar solo el contenido dejaría la tabla descuadrada respecto de su encabezado. El
    resto de las pantallas conserva el ancho de lectura, que es el correcto para formularios y prosa.
38. **(Adición técnica)** La tabla es de ancho fijo y sus columnas de texto se recortan con elipsis.
    Es lo que impide que el ancho de la tabla dependa de qué tan largo sea el nombre de un artículo,
    y por lo tanto lo que hace que "sin barra horizontal" sea una propiedad y no una casualidad del
    catálogo que se tenga cargado.
39. **(Adición técnica)** Los campos numéricos de la fila de filtros son de texto, no
    `<input type="number">`. Las flechitas del control nativo no caben en una celda tan angosta, y la
    validación ya vive en el servidor, que ignora en silencio lo que no sea un número.

## Estado de implementación

Los filtros por columna se implementaron el 2026-08-14; el orden de captura como orden base, sin
columna de id, el 2026-08-18.

- **Archivos con los filtros**: `app/Http/Controllers/ArticuloController.php` (las constantes
  `RANGOS` y `FILTROS_TEXTO`, y los métodos privados `filtrarPorColumna` y `numeroDeFiltro`),
  `frontend/src/stores/articulos.ts`, `frontend/src/views/ArticulosListView.vue`,
  `frontend/src/components/ColumnaOrdenable.vue` y `tests/Feature/ArticulosTest.php`.
- **El `CAST` del extremo de rango es obligatorio, no adorno.** Sin él los filtros de costo y de
  utilidad quedaban silenciosamente rotos: **todo mínimo no filtraba nada y todo máximo dejaba pasar
  el catálogo entero**. PDO no tiene parámetro de punto flotante, así que el extremo viaja al motor
  como texto; una suma como `costo_con_descuento + costo_goma` no arrastra la afinidad numérica de
  sus columnas, y comparado contra texto SQLite da el número por menor siempre. Se comparaba número
  contra cadena, no número contra número. El filtro de precio no lo destapó porque ahí la expresión
  es una columna sola, que sí conserva su afinidad — de no haber probado las tres columnas, el error
  habría llegado a producción por la mitad de los filtros.
- **Las tres columnas de dinero pasan por el mismo `whereRaw`**, incluida `precio_unitario_sin_iva`
  que sí es columna real: el bucle sobre `RANGOS` las trata igual y así comparten el `CAST`.
- **La tabla vacía distingue "no hay artículos" de "ningún artículo coincide"**: con filtros puestos,
  el mensaje de siempre —"No hay artículos registrados todavía"— afirmaría algo falso justo cuando el
  usuario más necesita entender por qué no ve nada.

### El ancho de la tabla (2026-08-14, tras la verificación visual del usuario)

La primera implementación dejó la tabla en el contenedor de lectura de siempre, `max-w-5xl`, y
confió en el `overflow-x-auto` de la tarjeta para lo que no cupiera. En pantalla eso resultó ser
inaceptable: las columnas más la fila de filtros no caben ni de lejos en ese ancho, así que la tabla
apareció comprimida, **con los botones de acciones fuera de la vista tras una barra de
desplazamiento** y con los campos de rango tan angostos que no se podía escribir un importe. Es el
mismo desborde que ya se había corregido en [006](006-gestion-articulos.md) el 2026-08-03,
reaparecido por la vía del ancho del contenedor en vez de por la del contenido de una celda.

- **`AppLayout` ganó la prop `ancho`** con `normal` por omisión, de modo que ninguna otra pantalla
  cambia. La clase se aplica a la barra superior, al menú móvil y al `<main>` a la vez.
- **La tabla pasó a `table-fixed`** con anchos declarados por columna y elipsis en Nombre, Modelo y
  Catálogo. Sin ancho fijo, un nombre largo vuelve a ensanchar la tabla y la barra regresa.
- **Los botones de acciones se envolvieron en un `div`**. Estaban en un `td` con `display:flex`, que
  saca a la celda del algoritmo de la tabla: dejaba de respetar el ancho de su columna, que es
  precisamente lo que los empujaba fuera.
- **Los campos numéricos de los filtros dejaron de ser `type="number"`**: las flechitas del control
  nativo se comían un tercio de cada celda. La tolerancia a lo que no sea un número ya estaba en el
  servidor, así que no se pierde nada.

### El orden de captura como orden base (2026-08-18)

La columna `id` ordenable resolvió el problema a medias: el orden de captura existía, pero había que
pedirlo con un clic cada vez que se entraba a la pantalla, y el número que había que ordenar no le
dice nada al usuario. El orden pasó a ser el que sale sin pedir nada y la columna desapareció.

- **Archivos modificados**: `app/Http/Controllers/ArticuloController.php`,
  `tests/Feature/ArticulosTest.php`, `frontend/src/stores/articulos.ts`,
  `frontend/src/views/ArticulosListView.vue` y `frontend/src/components/ColumnaOrdenable.vue`.
- **`ordenar()` cambió en dos líneas**: la caída pasó de `orderBy('nombre')` a `orderBy('id')`, y el
  desempate de las ordenaciones de dinero, de `nombre` a `id`. La clave `'id' => 'id'` salió de
  `ORDENACIONES`, así que `?sort=id` dejó de reconocerse y degrada al mismo orden de captura en vez
  de invertirlo: se prueba con un dataset que incluye `sort=id&direction=desc` y `sort=nombre`.
- **El filtro `filtro_id` se retiró del controlador**, y su caso pasó del dataset de "filtros
  inválidos" al de "parámetros que se ignoran".
- **Media docena de aserciones de orden de otros tests cambiaron de alfabético a captura.** No son
  ajustes cosméticos: son la prueba de que el orden nuevo alcanza al filtro por catálogo, a los
  rangos, a la exportación CSV y al `proveedor_id` de Orden de compra, que comparten `ordenar()`.
- **`ColumnaOrdenable` se quedó** aunque desapareció el motivo por el que se extrajo —el control ya
  no se dibuja en dos puntos del `<thead>`—: sigue concentrando el estado del control (qué flecha va,
  qué dice `aria-sort`) fuera de una cabecera que ya carga con su fila de filtros. Su comentario se
  corrigió para que no siga justificándose con una columna que ya no existe.
- **Verificación**: Pint pasa, la suite de Pest completa pasa (571 tests, 824 885 aserciones), ESLint
  y Prettier limpios, `npm run build` compila con `vue-tsc` y los 89 tests de Vitest siguen pasando.

### Pendiente de verificación visual

**No se pudo verificar visualmente en un navegador real** (misma limitación de entorno que el resto
de las historias; el proyecto no tiene herramienta de automatización de navegador instalada) — falta
abrir `/articulos` y confirmar que en escritorio no queda barra horizontal, que los dos botones de
acciones se ven enteros, que los campos de rango admiten un importe de cinco cifras y que el
selector de catálogo no se sale de su celda.
