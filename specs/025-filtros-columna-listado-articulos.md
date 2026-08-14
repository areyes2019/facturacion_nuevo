# Spec: Filtros por columna y orden de captura en el listado de artículos

## Historia de usuario

Como usuario que acaba de subir un CSV de doscientos artículos, quiero ver esos artículos juntos y
en el mismo orden que traía mi hoja de cálculo, y quiero poder acotar la tabla por columna —este
modelo, este catálogo, de tal precio a tal precio— para revisar lo que cargué sin recorrer catorce
páginas.

## Objetivo / Alcance

Dos cosas sobre el listado de `/articulos`, que se resuelven juntas porque tocan exactamente el
mismo código —`filtrarBusqueda` y `ordenar` en `ArticuloController`, y el estado de la tabla en
`stores/articulos.ts`— y comparten pantalla:

1. **Una columna `id` visible y ordenable**, que es el orden de captura: ordenada ascendente
   reproduce el orden del archivo importado.
2. **Un filtro por columna en cada cabecera de la tabla**: texto en `id`, Nombre, Modelo y Catálogo;
   rango desde–hasta en Costo, Precio e Utilidad.

**El buscador global de arriba se queda.** Sigue siendo la vía rápida para una palabra suelta y los
filtros por columna se combinan con él, no lo sustituyen.

Todo el filtrado y toda la ordenación ocurren **en el backend**. No hay ningún filtrado en el
navegador.

### El problema que resuelve

#### El orden no es el de la hoja

Al terminar una carga masiva, los artículos aparecen "revueltos" respecto del Excel. No lo están: la
importación crea las filas en el orden del archivo, una por una, y los `id` quedan en secuencia. Lo
que reordena es el listado, que sin `?sort=` cae a `orderBy('nombre')` — alfabético sobre **todo** el
catálogo, no sobre lo recién importado.

Combinado con la paginación de 15, el efecto es peor que un simple reordenamiento: los doscientos
artículos nuevos quedan repartidos entre las páginas de los que ya estaban, intercalados con ellos.
Revisar lo que acaba de entrar exige recorrer el listado completo.

Hoy no hay forma de pedir el orden de captura, porque el `id` no se muestra ni se puede ordenar por
él.

#### Un solo buscador no alcanza

El buscador actual es una caja de texto que pega contra nombre, modelo y proveedor a la vez. Sirve
para "búscame el Printer 38" y no sirve para nada más:

- No distingue columna. Un término que aparece en el nombre de un artículo y en el nombre comercial
  de un proveedor devuelve las dos cosas mezcladas.
- **No alcanza a las columnas de dinero.** No hay ninguna manera de preguntar "qué tengo entre 500 y
  800 pesos", que es justamente la pregunta con la que se revisan precios. Ordenar por precio ayuda
  a medias: hay que localizar a ojo dónde empieza el rango y dónde termina, saltando páginas.
- No acota por catálogo, aunque el listado ya sabe a qué catálogo pertenece cada artículo y lo
  muestra en su propia columna.

## Backend (Laravel)

### Ordenar por `id`

`ORDENACIONES` gana una entrada más: `'id' => 'id'`. El resto de `ordenar()` no cambia — misma
lectura de `?sort=` y `?direction=`, mismo desempate por `nombre`, misma caída al orden por defecto
cuando el `sort` no se reconoce.

**El orden por defecto sigue siendo alfabético por nombre.** No se cambia a `id`: el listado se usa
más para buscar un artículo conocido que para revisar una carga reciente, y para lo segundo ya está
la columna ordenable. Que el orden por defecto cambie bajo los pies de quien ya se acostumbró al
alfabético es un costo que esta historia no necesita pagar.

`id` no es una columna calculada, así que entra en la misma expresión `orderByRaw` sin caso especial:
`ORDENACIONES` no exige que sus valores sean fórmulas, solo que sean expresiones SQL válidas.

### Los filtros

Todos viven en `filtrarBusqueda`, que ya recibe la `Request` y ya es el único punto por el que pasan
tanto el listado como la exportación CSV. Cada filtro es un `when()` más, y **todos se combinan con
Y** entre sí y con el `search` global y el `proveedor_id` que ya existían.

| Parámetro | Columna | Comportamiento |
| --- | --- | --- |
| `filtro_id` | `id` | Igualdad exacta. Se ignora si no es un entero positivo. |
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

**Un extremo vacío, no numérico o negativo se ignora en silencio**, igual que hoy se ignora un
`sort` que no se reconoce. Un filtro a medio escribir no debe producir un error en pantalla ni
vaciar la tabla: mientras el usuario teclea "1", "12", "125", los tres estados son legítimos.

**Un rango invertido —mínimo mayor que máximo— devuelve cero resultados** y no se corrige ni se
advierte. Es lo que literalmente se pidió, la tabla vacía lo comunica de inmediato, y adivinar que
el usuario quiso decir lo contrario sería peor que obedecerle.

#### Costo y utilidad no son columnas de la tabla

`costo_total` y `utilidad` **no están persistidos**: se calculan. Sus filtros de rango van con
`whereRaw` sobre **exactamente las mismas expresiones** que ya usa `ORDENACIONES` para ordenarlos
([014](014-costo-elaboracion-goma.md)). Las expresiones se declaran una sola vez y las consumen
ordenación y filtro, para que no puedan divergir: una tabla donde ordenar por costo y filtrar por
costo entendieran cosas distintas sería indefendible.

`precio_unitario_sin_iva` sí es columna real y usa un `whereBetween` normal.

### La exportación CSV hereda todo

`exportarCsv` ya reusa `filtrarBusqueda` y `ordenar`, así que **exporta exactamente lo que la tabla
está mostrando** sin una línea de código nueva. Es la propiedad que hace que los filtros valgan más
de lo que cuestan: acotar en pantalla y bajarse ese subconjunto ya es un flujo completo.

## Frontend (Vue 3)

### La columna `id`

Se agrega como **primera columna después del checkbox de selección**, alineada a la derecha y con
`tabular-nums`, y es ordenable con el mismo control de cabecera que ya usan Costo, Precio e
Utilidad. `ArticuloSort` gana `'id'`.

La cabecera dice **`id`** y no "orden de captura": es el dato que se muestra, y el nombre corto es el
que cabe en una columna de números. El orden de captura es lo que se obtiene al ordenarla
ascendente, no un concepto aparte que haya que explicar.

El `id` ya viaja en `ArticuloResource` y ya está en la interfaz `Articulo` del store. No hace falta
nada del backend para mostrarlo.

### La fila de filtros

Debajo de la fila de cabeceras, dentro del mismo `<TableHeader>`, va **una segunda fila con un
control por columna**:

```
[ ] │ id      │ Nombre    │ Modelo    │ Catálogo   │ Costo    │ Precio   │ Utilidad │ Acciones
    │ [ = ]   │ [contiene]│ [contiene]│ [Todos  ▾] │ [min–max]│ [min–max]│ [min–max]│
```

- **`id`**: un campo numérico de igualdad exacta.
- **Nombre** y **Modelo**: campos de texto, "contiene", sin distinguir mayúsculas.
- **Catálogo**: un `<select>` con "Todos los catálogos" como opción por defecto, alimentado por el
  listado de catálogos que ya consume `CatalogoSelect.vue`. Es un selector y no texto libre porque
  los catálogos son un conjunto cerrado y corto, y escribir el nombre a mano solo abre la puerta a
  no encontrar nada por una tilde.
- **Costo**, **Precio**, **Utilidad**: dos campos numéricos pequeños, mínimo y máximo.
- Las columnas de selección y de acciones no llevan filtro.

**Los filtros no se ocultan detrás de un menú por columna.** Están siempre visibles en su fila. Un
filtro escondido tras un ícono obliga a abrir seis menús para saber qué está aplicado, y el
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

Ocho columnas más una fila de controles es lo más ancho que ha estado esta tabla. Se sostienen las
reglas de [003](003-design-system-tailwind.md): la tabla dentro de su contenedor con
`overflow-x-auto`, cada control con `min-w-0`, y los campos de rango angostos —caben dos y un guion
en el ancho de una columna de dinero—. **El `<body>` nunca hace scroll horizontal**; si la tabla no
cabe, la que se desplaza es la tabla.

## Fuera de alcance

- **Cambiar el orden por defecto del listado.** Sigue siendo alfabético por nombre; el orden de
  captura se pide ordenando la columna `id`.
- **Filtros en otros listados** (clientes, proveedores, catálogos, facturas, cotizaciones, órdenes de
  compra). Esta historia toca únicamente `/articulos`. Si el patrón demuestra servir, extenderlo es
  otra historia con su propio spec.
- **Guardar o recordar los filtros** entre visitas a la pantalla, en `localStorage` o en la URL.
  Cada entrada al listado empieza sin filtros.
- **Filtros combinables con O**, negaciones ("que no contenga"), o comodines dentro del texto. Todo
  se combina con Y y el texto es siempre "contiene".
- **Rangos de fecha** por `created_at` o `updated_at`. La columna `id` ordenada ya cubre el caso
  real —"lo que acabo de subir"— sin agregar dos campos de calendario a la cabecera.
- **Filtrar por `clave_prod_serv`, `clave_unidad`, `objeto_imp`, `tamano_goma` o proveedor**, que no
  tienen columna propia en la tabla. Sin columna visible no hay dónde poner el filtro, y esta
  historia no agrega columnas nuevas salvo `id`.
- **Filtrar por precio con IVA.** La columna que la tabla muestra es la de sin IVA, y es sobre la
  que se filtra.
- **Seleccionar todos los resultados del filtro** para el borrado en lote. La selección sigue siendo
  de la página visible ([021](021-mantenimiento-articulos-catalogos.md)).
- **Un buscador global que entienda sintaxis** tipo `precio:>500`. Se filtra con controles, no con
  lenguaje.
- Roles/permisos diferenciados o multiempresa, como en todas las historias anteriores.

## Criterios de aceptación

1. El listado de `/articulos` muestra una columna `id` entre el checkbox de selección y Nombre, con
   los números alineados a la derecha.
2. La cabecera `id` es ordenable con el mismo control que Costo, Precio e Utilidad, y alterna
   ascendente → descendente → sin ordenar.
3. Ordenar por `id` ascendente devuelve los artículos en el orden en que fueron creados, de modo que
   los de una importación CSV aparecen consecutivos y en el orden de las filas del archivo.
4. Sin ninguna ordenación elegida, el listado sigue saliendo alfabético por nombre, como antes de
   esta historia.
5. Debajo de las cabeceras hay una fila de filtros con: campo numérico exacto en `id`, campo de
   texto en Nombre y en Modelo, selector con "Todos los catálogos" en Catálogo, y par mínimo–máximo
   en Costo, Precio e Utilidad. Las columnas de selección y acciones no llevan control.
6. Escribir en el filtro de Nombre acota el listado a los artículos cuyo nombre contiene ese texto,
   sin distinguir mayúsculas ni exigir que sea el principio del nombre. Lo mismo para Modelo.
7. El filtro de `id` con un número devuelve a lo sumo un artículo.
8. Elegir un catálogo en su filtro deja solo los artículos de ese catálogo; volver a "Todos los
   catálogos" los devuelve.
9. Un rango con mínimo y máximo devuelve los artículos cuyo valor cae entre ambos, incluidos los
   extremos, en cada una de las tres columnas de dinero.
10. Un rango con solo mínimo devuelve todo lo que esté por encima; con solo máximo, todo lo que esté
    por debajo.
11. Un rango invertido (mínimo mayor que máximo) devuelve cero resultados, sin error en pantalla.
12. Un extremo de rango vacío, no numérico o negativo se ignora: la tabla se comporta como si ese
    extremo no se hubiera escrito, sin error y sin vaciarse.
13. Los filtros de Costo y de Utilidad operan sobre los mismos valores calculados que muestra la
    tabla y por los que se ordena: un artículo que la tabla muestra con costo de $120 aparece en el
    rango 100–150 y no en el 0–100.
14. Varios filtros a la vez se combinan con Y: modelo que contiene "Printer" **y** precio entre 500
    y 800 devuelve solo los que cumplen las dos condiciones.
15. Los filtros de columna se combinan con el buscador global, que sigue en su lugar y sigue pegando
    contra nombre, modelo y proveedor.
16. Los filtros se combinan con la ordenación: filtrar y luego ordenar por `id` conserva el filtro, y
    viceversa.
17. Cambiar cualquier filtro devuelve la paginación a la página 1.
18. Escribir en un filtro de texto no lanza una petición por tecla: la consulta sale tras una pausa,
    como ya ocurre con el buscador global.
19. Con al menos un filtro de columna aplicado aparece un botón "Limpiar filtros" que los borra
    todos; el buscador global conserva su contenido.
20. Exportar CSV con filtros aplicados descarga exactamente los artículos que la tabla está
    mostrando, en el mismo orden, con las mismas columnas de siempre.
21. La selección múltiple y el borrado en lote siguen funcionando sobre la página visible del
    resultado filtrado, y la selección se vacía cuando el filtro cambia las filas.
22. El endpoint sigue respondiendo lo mismo que hoy cuando no se le manda ningún parámetro de filtro
    nuevo, y `proveedor_id` sigue acotando el selector de artículos de Orden de compra
    ([012](012-ordenes-compra.md)).
23. La tabla con las ocho columnas y la fila de filtros se ve completa en escritorio (≥1280px) y en
    móvil sin que la página desborde horizontalmente; si algo se desplaza, es la tabla dentro de su
    contenedor.
24. Pint corre sin errores sobre el código de backend modificado, ESLint y Prettier sobre el de
    frontend, la suite de Pest sigue pasando y `npm run build` compila la SPA completa.

## Supuestos asumidos (registro completo)

1. Los artículos ya se crean en el orden del archivo importado; lo que hay que arreglar es la forma
   de pedir ese orden, no la importación.
2. El orden por defecto del listado no cambia: sigue siendo alfabético por nombre.
3. El orden de captura se expone como la columna `id` ordenable, no como una opción con nombre
   propio en un menú aparte.
4. La columna `id` se muestra siempre, no detrás de una preferencia ni de un modo avanzado.
5. El buscador global se queda y convive con los filtros de columna; no lo sustituyen.
6. Todos los filtros se combinan con Y, entre ellos y con el buscador global.
7. Los filtros de texto son "contiene", sin distinguir mayúsculas.
8. El filtro de `id` es igualdad exacta: un id es un id, no un prefijo.
9. El filtro de catálogo es un selector de la lista existente, no texto libre.
10. Las columnas de dinero se filtran por rango desde–hasta, no por valor exacto: nadie busca un
    precio exacto, busca un tramo.
11. Cada extremo de un rango es opcional por separado.
12. Un filtro mal escrito o a medio escribir se ignora en silencio; nunca produce error en pantalla
    ni vacía la tabla por un motivo que el usuario no pidió.
13. Un rango invertido devuelve cero resultados y no se corrige solo.
14. Los filtros no se recuerdan entre visitas ni viajan en la URL: cada entrada empieza limpia.
15. "Limpiar filtros" borra los de columna y no el buscador global.
16. Los filtros están siempre visibles en su fila, no escondidos tras un menú por columna.
17. La exportación CSV respeta filtros y orden, porque exportar lo que se está viendo es el
    comportamiento esperado.
18. La selección múltiple sigue siendo de la página visible, también con filtros aplicados.
19. Solo se filtra por las columnas que la tabla muestra. Ninguna columna nueva salvo `id`.
20. Esta historia toca únicamente el listado de artículos; los demás listados del sistema quedan
    como están.
21. **(Adición técnica)** Todo el filtrado y la ordenación ocurren en el servidor, nunca en el
    navegador: la tabla está paginada de 15 en 15, y un filtro que corriera sobre los datos ya
    descargados solo acotaría los quince renglones a la vista.
22. **(Adición técnica)** Costo total y utilidad no son columnas de la base de datos, sino
    expresiones calculadas. Sus filtros usan las mismas expresiones que ya usa la ordenación
    ([014](014-costo-elaboracion-goma.md)), declaradas en un solo lugar para que filtrar y ordenar
    no puedan entender cosas distintas.
23. **(Adición técnica)** Los parámetros nuevos son aditivos: una petición sin ellos responde
    exactamente lo que respondía antes, así que nada de lo que hoy consume el endpoint —incluido el
    `proveedor_id` de Orden de compra— se ve afectado.
24. **(Adición técnica)** La exportación CSV no necesita código nuevo: ya reusa el mismo filtrado y
    la misma ordenación que el listado.
25. **(Adición técnica)** Los campos de texto y numéricos comparten el rebote de 300 ms que ya tiene
    el buscador global; el selector de catálogo consulta de inmediato.

## Estado de implementación

Implementada el 2026-08-14.

- **Archivos modificados**: `app/Http/Controllers/ArticuloController.php` (la clave `id` en
  `ORDENACIONES`, las constantes `RANGOS` y `FILTROS_TEXTO`, y los métodos privados
  `filtrarPorColumna` y `numeroDeFiltro`), `frontend/src/stores/articulos.ts`,
  `frontend/src/views/ArticulosListView.vue` y `tests/Feature/ArticulosTest.php`.
- **Archivo nuevo**: `frontend/src/components/ColumnaOrdenable.vue`, la cabecera que ordena por su
  columna. Se extrajo porque `id` va al principio de la tabla y las columnas de dinero al final, así
  que el mismo control tenía que dibujarse en dos lugares del `<thead>` y duplicar el marcado habría
  dejado dos `aria-sort` que mantener en sincronía.
- **El `CAST` del extremo de rango es obligatorio, no adorno.** Sin él los filtros de costo y de
  utilidad quedaban silenciosamente rotos: **todo mínimo no filtraba nada y todo máximo dejaba pasar
  el catálogo entero**. PDO no tiene parámetro de punto flotante, así que el extremo viaja al motor
  como texto; una suma como `costo_con_descuento + costo_goma` no arrastra la afinidad numérica de
  sus columnas, y comparado contra texto SQLite da el número por menor siempre. Se comparaba número
  contra cadena, no número contra número. El filtro de precio no lo destapó porque ahí la expresión
  es una columna sola, que sí conserva su afinidad — de no haber probado las tres columnas, el error
  habría llegado a producción por la mitad de los filtros.
- **`precio_unitario_sin_iva` acabó pasando por el mismo `whereRaw` que las otras dos** y no por un
  `whereBetween` aparte como anticipaba la spec: el bucle sobre `RANGOS` las trata igual y el
  resultado es idéntico, con la ventaja de que las tres columnas comparten camino y por lo tanto
  comparten el `CAST` que hizo falta.
- **La tabla vacía distingue "no hay artículos" de "ningún artículo coincide"**. La spec no lo
  fijaba, pero con filtros puestos el mensaje de siempre —"No hay artículos registrados todavía"—
  afirmaría algo falso justo cuando el usuario más necesita entender por qué no ve nada.
- **Verificación**: Pint pasa, la suite de Pest completa pasa (464 tests, 23 627 aserciones), ESLint
  y Prettier limpios, `npm run build` compila con `vue-tsc` y los 65 tests de Vitest siguen pasando.
  Se agregaron 12 pruebas de backend, con datasets para las tres columnas de dinero, los dos
  extremos por separado, el rango invertido y las siete formas de filtro inválido.

### Pendiente de verificación visual

**No se pudo verificar visualmente en un navegador real** (misma limitación de entorno que el resto
de las historias) — falta abrir `/articulos` para confirmar el ancho de la fila de filtros con las
nueve columnas, que el selector de catálogo quepa en su celda, y que la tabla se desplace dentro de
su contenedor sin que la página desborde en móvil.
