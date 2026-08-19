# Spec: Design System con Tailwind CSS

## Historia de usuario

Como desarrollador, quiero establecer un estilo único y consistente para todo mi sistema
(Laravel API + Vue 3 SPA) usando **Tailwind CSS**, definiendo un **design system** propio
(paleta de colores, tipografía, spacing, componentes base reutilizables), y aplicándolo mediante
**wireframes** (estructura/disposición en baja fidelidad) y **mockups** (aplicación real del
estilo) sobre las pantallas ya existentes (login, recuperación de contraseña, dashboard) y las
futuras.

## Objetivo / Alcance

Instalar y configurar Tailwind CSS v4 en el frontend Vue 3 ya existente (ver
[001-inicio-proyecto.md](001-inicio-proyecto.md) y [002-login-auth.md](002-login-auth.md)),
definir los tokens de diseño (colores, tipografía, spacing) y un set de componentes base
reutilizables, y aplicar retroactivamente ese estilo a las pantallas de auth (`/login`,
`/forgot-password`, `/reset-password`) y al `/dashboard`. Incluye una página interna de
documentación del design system.

## Frontend (Vue 3)

- **Tailwind CSS v4** instalado vía el plugin oficial de Vite (`@tailwindcss/vite`), reemplazando
  el estado "sin librería de estilos" de la spec 002.
- **Componentes base**: se adopta **shadcn-vue** (con Radix Vue como base de accesibilidad/lógica)
  — los componentes se copian al proyecto en `components/ui/` (no son una dependencia externa
  fija) y quedan libremente editables. Componentes iniciales: Button, Input, Card, Alert/Toast,
  Badge, Modal.
- **Paleta de colores**: definida como tokens propios en `@theme` de Tailwind (primario,
  secundario, neutros, y estados éxito/error/advertencia), no los colores default sin
  personalizar.
- **Tipografía dual**:
  - **Roboto** para títulos/headings (`h1`–`h6`, y utilidad `font-heading`).
  - **Open Sans** para texto de cuerpo (resto de la UI, utilidad `font-sans` por defecto).
  - Ambas cargadas vía Google Fonts.
- **Iconografía**: Heroicons.
- **Spacing/tokens**: centralizados en la configuración de Tailwind (`@theme`), no dispersos por
  componente.
- **Responsive**: mobile-first, usando los breakpoints default de Tailwind (`sm`/`md`/`lg`/`xl`/`2xl`),
  en línea con la futura PWA/Capacitor (ver 001).
- **Modo oscuro**: fuera de alcance de esta historia.
- **Identidad visual**: no existe logo/marca real todavía; se usa un placeholder de texto (nombre
  del sistema) en header/login.
- **Wireframes**: las pantallas ya existentes (`/login`, `/forgot-password`, `/reset-password`,
  `/dashboard`) se construyen/revisan primero en baja fidelidad (escala de grises, sin color ni
  tipografía final) para validar disposición y layout.
- **Mockups**: esas mismas pantallas se llevan a implementación real con la paleta, tipografía y
  componentes definitivos (no se entrega como imagen estática, sino como el código funcionando).
- **Página de design system**: ruta interna `/design-system`, accesible solo en entorno de
  desarrollo, que muestra los tokens (colores, tipografía, spacing) y cada componente base con
  sus variantes, a modo de documentación viva.

### Primitivos de menú: cuál usar

El proyecto usa tres primitivos flotantes de shadcn-vue / Reka UI, y la elección no es de gusto:
cada uno se anuncia distinto a los lectores de pantalla y aporta un juego distinto de atajos de
teclado.

| Primitivo | Cuándo | Ejemplo |
| --- | --- | --- |
| `NavigationMenu` | El contenido son **solo destinos de navegación** | Grupos Ventas / Compras / Inventario / Contabilidad ([013](013-navegacion-principal.md)) |
| `DropdownMenu` | El contenido **mezcla destinos y acciones**, o son solo acciones | Menú de usuario: Configuración (destino) + Cerrar sesión (acción) ([013](013-navegacion-principal.md)) |
| `Popover` | El contenido **no es un menú**: es un panel con campos, texto o controles | Filtros, ayuda contextual |

La regla práctica: si al abrirlo el usuario espera una lista de opciones que se recorre con las
flechas, es un menú (`NavigationMenu` o `DropdownMenu`). Si espera un panelito con contenido, es un
`Popover`. Y entre los dos menús, decide si hay al menos una entrada que **hace algo** en vez de
llevar a otra pantalla: si la hay, es `DropdownMenu`.

### La ausencia de selección en un `Select`

`Select` de Reka UI reserva la **cadena vacía** para su `v-model`: asignarla limpia la selección y
muestra el `placeholder`. Como contrapartida, ningún `SelectItem` puede valer `''` — el primitivo lo
rechaza al montarse y esa opción no llega a renderizarse.

Cuando "ninguno" es una elección legítima del usuario y no un "falta capturar" (Sin goma en
[014](014-costo-elaboracion-goma.md), "Todos los estados" en un filtro), esa opción se declara con
un **valor centinela** junto al resto de las opciones (`'sin-goma'`, `'todos'`) y se traduce a
`''`/`null` en el límite del formulario, con un `computed` de lectura y escritura. El estado del
formulario nunca guarda el centinela: lo ve el `Select` y nadie más.

La distinción con el `placeholder` no es cosmética. El `placeholder` dice "falta elegir" y se pinta
en `text-muted-foreground`; una opción seleccionada dice "elegí que no" y se pinta como cualquier
otro valor. Un `Select` donde el único camino de vuelta a "ninguno" sea recargar la pantalla está
mal construido, aunque el `placeholder` muestre la frase correcta.

La regla la hace cumplir ESLint (`vue/no-restricted-static-attribute` sobre `SelectItem`), de modo
que la aplicación no puede volver a quedarse sin esa opción. No alcanza a los `<select>` nativos,
donde `<option value="">` es válido y de uso corriente en los filtros de los listados.

### Escribir y leer un `defineModel` en el mismo manejador

Un ref de `defineModel` **no se puede leer de vuelta en el mismo tick en que se escribió** si el
componente padre lo usa con `v-model`. La lectura devuelve el valor anterior.

No es un detalle de implementación accidental, es deliberado: cuando el padre pasa la prop y el
`onUpdate:`, `useModel` de Vue solo emite el evento y deja que el valor local se sincronice cuando
el padre devuelva la prop nueva, en el siguiente ciclo de render.

```js
// Mal: `modelValue.value` todavía vale lo de antes.
modelValue.value = valor ? Number(valor) : null
emit('seleccion', resultados.value.find((c) => c.id === modelValue.value) ?? null)

// Bien: el valor recién elegido ya está en la mano, no hace falta ir a buscarlo.
const id = valor ? Number(valor) : null
modelValue.value = id
emit('seleccion', resultados.value.find((c) => c.id === id) ?? null)
```

La regla aplica a cualquier manejador que actualice el modelo y en la misma pasada necesite el valor
nuevo: emitir un segundo evento, derivar un dato, decidir una navegación. El valor nuevo siempre
llega como argumento o como variable local; usarlo de ahí es más corto además de correcto.

**Sin regla de ESLint**: el patrón —una escritura y una lectura del mismo ref en un manejador— no se
distingue estáticamente de código legítimo, y una regla a medida saldría más cara que el beneficio.
Queda como regla escrita, con el caso que la originó anotado en
[015](015-descuento-permanente-cliente.md).

El síntoma es traicionero porque el `v-model` **sí funciona**: el padre recibe el valor correcto por
el evento. Lo único que falla es la lectura de vuelta, así que la pantalla se ve bien y lo que se
rompe es el efecto secundario. Y no falla igual siempre: en la segunda selección el valor local ya
alcanzó al de la primera, con lo que la lectura devuelve el elemento *anterior* en vez de nada — de
"no pasa nada" a "pasa lo que no era", que es peor.

### Incorporar un primitivo nuevo

Cada `npx shadcn-vue add ...` reescribe bloques enteros de `src/style.css` (ver "Estado de
implementación"). El paso posterior **no es opcional**: comparar `src/style.css` contra su estado
previo y revertir lo que el CLI haya sobrescrito, en particular el `@import` de Google Fonts, el
`@theme` y el `@layer base`.

No es una hipótesis: ocurrió al instalar `navigation-menu` en [013](013-navegacion-principal.md) y
volvió a ocurrir, idéntico, al instalar `dropdown-menu` en
[014](014-costo-elaboracion-goma.md) — en ambos casos el CLI dejó la aplicación sin Open Sans. La
forma más rápida de verificarlo es comparar el hash del archivo antes y después del `add`.

## Fuera de alcance

- Modo oscuro (dark mode).
- Diseño/creación de un logo o identidad de marca real.
- Auditoría formal de accesibilidad (solo se busca contraste razonable AA básico).
- Uso de herramientas de diseño externas (Figma, Sketch, XD): todo se construye directamente en
  código Vue + Tailwind.
- Cualquier lógica de negocio de facturación (esta historia es puramente de estilo/UI).

## Estado de implementación

Implementada el 2026-07-30.

- **Radix Vue → Reka UI**: el paquete que usa shadcn-vue actualmente se llama `reka-ui` (Radix Vue
  fue renombrado por sus mismos mantenedores). Funcionalmente es lo que pedía la spec.
- **shadcn-vue CLI, detalles no obvios**:
  - `init` necesita que Tailwind y el alias `@/*` ya existan (en `tsconfig.json` **y**
    `tsconfig.app.json`, no solo uno) antes de correr, si no falla con "no configuration found".
  - `--base-color` no acepta `slate`/`gray`/`zinc` como decía el `--help`; con `--base reka` los
    valores válidos son `neutral`, `stone`, `zinc`, `mauve`, `olive`, `mist`, `taupe`. Se usó
    `neutral` (luego sobreescrito igual por nuestros tokens propios).
  - El picker de fuente es interactivo salvo que se pase `--font <valor>` explícito (se usó
    `--font roboto`, aunque solo como placeholder — la tipografía real se definió a mano en
    `@theme`).
  - **Cada `npx shadcn-vue add ...` reescribe bloques enteros de `src/style.css`** (variables CSS,
    imports de Google Fonts, `@layer base`), pisando ediciones manuales previas. Se corrió `init` +
    un solo `add button input card badge alert dialog` juntos, y recién después se escribieron los
    tokens de color/tipografía definitivos, para no pelear con el CLI. Si se agregan más
    componentes en el futuro con `add`, hay que revisar `style.css` después por si vuelve a
    clobberear el `@theme`/`:root` custom.
- **Iconos**: el CLI de shadcn-vue no ofrece Heroicons como `--icon-library` (solo
  lucide/tabler/hugeicons/phosphor/remixicon); se dejó `@lucide/vue` únicamente como dependencia
  interna de scaffolding (no se usa en código propio) y se instaló `@heroicons/vue` aparte para
  todo ícono usado en las pantallas de la app (ej. el de "Cerrar sesión" en el dashboard).
- **Alert vs Toast, Modal vs Dialog**: se implementó solo `Alert` (ver aclaración #19); "Modal" se
  resolvió usando el componente `Dialog` de shadcn-vue/Reka UI directamente (es lo mismo, Reka UI
  no tiene un primitivo separado llamado "Modal").
- Se agregaron variantes `success`/`warning` a `Alert` y `Badge` (no vienen por defecto en el
  registry de shadcn-vue) para reflejar los tres estados de la paleta.
- ESLint: se desactivó `vue/multi-word-component-names` y `vue/require-default-prop` solo para
  `src/components/ui/**/*.vue`, porque los componentes vendored de shadcn-vue siguen su propia
  convención de nombres (`Button.vue`, `Card.vue`, etc.) distinta a la del resto del código de la
  app.
- Verificado con Playwright en viewports mobile (375px), tablet (768px) y desktop (1440px): las 4
  pantallas de auth, `/dashboard` y `/design-system` renderizan consistentes y responsive, sin
  errores de consola reales.
- **Regla de layout para `Dialog` con contenido dinámico** (agregada el 2026-07-31, tras
  detectarse un desbordamiento real en el modal de importación CSV de
  [006-gestion-articulos.md](006-gestion-articulos.md)): `DialogContent` es `display: grid`, y los
  hijos directos de un contenedor grid tienen `min-width: auto` por defecto — un texto sin puntos
  de quiebre (una cadena larga sin espacios) o un control con ancho intrínseco variable (ej.
  `<input type="file">`, cuyo ancho crece con el nombre del archivo elegido) pueden forzar a ese
  hijo a expandirse más allá del `max-w-lg` fijo del modal, desbordando el contenido en vez de
  encogerse o envolver línea. No estaba cubierto por el criterio de "responsive" original de esta
  historia (pensado para el viewport, no para contenido dinámico dentro de un modal de ancho fijo).
  Regla adoptada para todo `Dialog` de la app, presente y futuro:
  1. El contenedor inmediato de cada bloque de contenido dentro de `DialogContent` lleva `min-w-0`
     explícito.
  2. Texto libre que pueda incluir cadenas largas sin espacios (listas separadas por comas, rutas,
     nombres de archivo) se envuelve con `break-words`/`overflow-wrap-anywhere`, o se muestra en un
     bloque aparte con `overflow-x-auto` (ej. `<code>`) en vez de ir embebido en un párrafo de
     prosa.
  3. Controles nativos de ancho variable (`<input type="file">`) van dentro de un contenedor con
     `min-w-0` + `truncate` para que el nombre del archivo se recorte en vez de ensanchar el modal.
  - **Pendiente**: esta regla queda documentada aquí como referencia para todos los `Dialog`
    existentes y futuros; su aplicación al modal concreto de importación CSV se registra como
    trabajo pendiente en [006-gestion-articulos.md](006-gestion-articulos.md).
- **Regla de la opción "ninguno" en un `Select`** (agregada el 2026-08-07, tras detectarse que el
  selector de tamaño de goma de [014](014-costo-elaboracion-goma.md) se quedaba sin su opción "Sin
  goma"): Reka UI lanza en `setup` si un `SelectItem` vale cadena vacía, y `SelectContent` monta sus
  opciones en un `DocumentFragment` oculto aun con el desplegable cerrado, así que el error aparece
  al entrar a la pantalla y no al abrir el control. El resto de las opciones sí monta, de modo que
  el síntoma visible no es una pantalla rota sino una opción ausente. La regla resultante quedó
  arriba, en "La ausencia de selección en un `Select`", y se hace cumplir con
  `vue/no-restricted-static-attribute` en `eslint.config.js`. `vue-tsc` no puede detectarlo —
  `''` es un `string` válido y la restricción es de tiempo de ejecución.
- **Regla de la lectura de vuelta de un `defineModel`** (agregada el 2026-08-08, tras detectarse que
  el `ClienteCombobox` de [015](015-descuento-permanente-cliente.md) emitía siempre el cliente
  anterior): `useModel` de Vue 3.5 solo actualiza su valor local en el acto cuando el padre **no**
  usa `v-model` (`runtime-core`, comprobación `hasVModel`); con `v-model`, emite y espera a que la
  prop vuelva en el siguiente ciclo de render. La regla resultante quedó arriba, en "Escribir y leer
  un `defineModel` en el mismo manejador". Ni `vue-tsc` ni ESLint pueden detectarlo: los tipos son
  correctos y el patrón no se distingue estáticamente de una lectura legítima.
- **Regla del ancho de página y de las tablas de listado** (agregada el 2026-08-14, tras quedar el
  listado de artículos de [025](025-filtros-columna-listado-articulos.md) comprimido en el ancho de
  lectura, con los botones de acciones escondidos tras una barra de desplazamiento). Cierra además el
  **pendiente** que dejó [006](006-gestion-articulos.md) el 2026-08-03: formalizar aquí, como regla
  general de `Table`, el truncado que entonces se resolvió con una prop de `TableCell`.

  El ancho de lectura por omisión de `AppLayout` es el correcto para formularios y prosa, y el
  equivocado para un listado denso: una tabla que no cabe no se encoge, se desborda, y lo que queda
  fuera es la última columna —la de acciones— sin que nada en pantalla anuncie que está ahí. **Una
  barra de desplazamiento dentro de una tabla es una forma de esconder controles, no de mostrarlos.**

  1. `AppLayout` acepta una prop `ancho` (`normal` por omisión, `amplio` para listados densos). La
     clase ensancha la barra superior, el menú móvil y el `<main>` **a la vez**: ensanchar solo el
     contenido deja la tabla descuadrada respecto de su encabezado. El listado de artículos volvió a
     `normal` el 2026-08-19, al quedarse en cuatro columnas de datos, así que hoy **ninguna pantalla
     pide `amplio`**: la prop se queda como capacidad del layout para el siguiente listado denso, no
     como resto de aquella.
  2. Una tabla de listado con muchas columnas va con **ancho fijo** (`table-fixed`) y un ancho
     declarado por columna, dejando sin declarar solo la columna que deba quedarse con el sobrante.
     Sin ancho fijo, el ancho de la tabla lo decide el dato más largo que haya cargado, y "cabe" pasa
     a ser una casualidad de los datos en vez de una propiedad del diseño.
  3. Toda columna de texto libre de una tabla de ancho fijo **se recorta con elipsis** y expone el
     texto completo en el `title`, con la prop `truncate` de `TableCell` o con `truncate` sobre el
     elemento de bloque que haya dentro de la celda.
  4. Los controles de una celda —botones de acción, campos de filtro— van **dentro de un contenedor**
     propio, nunca convirtiendo el `<td>` en flex: un `display:flex` sobre la celda la saca del
     algoritmo de la tabla y deja de respetar el ancho de su columna.
  5. En una celda angosta no se usa `<input type="number">`: las flechitas del control nativo se
     comen buena parte del ancho útil. Va un campo de texto con `inputmode` y la validación del lado
     del servidor.
  - Nada de esto lo detectan `vue-tsc` ni ESLint: son propiedades de lo que se ve, y solo aparecen
    abriendo la pantalla con datos reales.

## Criterios de aceptación

1. Tailwind CSS v4 está instalado y funcionando en `frontend/`, con el plugin de Vite configurado.
2. Existen componentes base en `components/ui/` (Button, Input, Card, Alert/Toast, Badge, Modal)
   basados en shadcn-vue/Radix Vue, estilizados con los tokens del design system.
3. La paleta de colores, la tipografía (Roboto para títulos, Open Sans para cuerpo) y el spacing
   están centralizados en la configuración de Tailwind (`@theme`), no hardcodeados en componentes
   individuales.
4. Las pantallas `/login`, `/forgot-password`, `/reset-password` y `/dashboard` usan el nuevo
   estilo (colores, tipografía, componentes base) de forma consistente entre sí.
5. Existe la ruta `/design-system` (solo en desarrollo) que documenta los tokens y componentes
   base con sus variantes.
6. El diseño es responsive y usable correctamente en viewports mobile, tablet y desktop.
7. ESLint/Prettier corren sin errores sobre el código nuevo.
8. La elección entre `NavigationMenu`, `DropdownMenu` y `Popover` sigue la regla documentada: solo
   destinos → `NavigationMenu`; destinos mezclados con acciones, o solo acciones → `DropdownMenu`;
   contenido que no es una lista de opciones → `Popover`.
9. Tras cada `npx shadcn-vue add`, `src/style.css` conserva íntegros el `@import` de Google Fonts
   con ambas familias, el `@theme` y los tokens propios.
10. Ningún manejador que escriba un ref de `defineModel` lee ese mismo ref en la misma pasada para
    derivar un dato, emitir otro evento o decidir una navegación: usa el valor que ya tiene a mano.
11. `AppLayout` acepta el ancho `amplio` para listados densos y conserva el ancho de lectura por
    omisión, de modo que una pantalla que no lo pida se ve exactamente igual que antes.
12. Ninguna tabla de listado esconde su columna de acciones tras una barra de desplazamiento
    horizontal en escritorio (≥1280px), y el ancho de una tabla no depende de qué tan largo sea el
    dato más largo que tenga cargado.

## Supuestos asumidos (registro completo)

1. Alcance: aplica solo al frontend Vue 3 (no hay estilos que tocar en el backend Laravel).
2. Tailwind CSS se instala en su última versión estable (v4) vía el plugin oficial de Vite.
3. **(Redefinido)** Se usa **shadcn-vue** (con Radix Vue) para los componentes base: se copian al
   proyecto y quedan libremente editables, en lugar de construir todo desde cero con solo
   utilidades de Tailwind o depender de un kit cerrado (PrimeVue/Vuetify).
4. Se define una paleta de colores propia (primario, secundario, neutros, estados) en la
   configuración de Tailwind, no los colores default sin personalizar.
5. **(Redefinido)** Tipografía dual: **Roboto** para títulos/headings y **Open Sans** para texto
   de cuerpo, ambas vía Google Fonts (en lugar de una sola familia).
6. Modo oscuro (dark mode) queda fuera de alcance de esta historia.
7. Breakpoints responsive: los defaults de Tailwind, enfoque mobile-first.
8. El "wireframe" se entrega como las mismas pantallas ya existentes renderizadas en baja
   fidelidad (escala de grises, sin color ni tipografía final) para validar disposición/layout.
9. El "mockup" se entrega como esas mismas pantallas ya con la paleta y tipografía definitivas
   aplicadas — la implementación real, no una imagen estática exportada.
10. El "design system" se documenta como una página interna en la propia SPA (`/design-system`,
    solo en desarrollo) que muestra los componentes base y tokens, en vez de un archivo Figma
    externo.
11. Componentes base a crear en `components/ui/`: Button, Input, Card, Alert/Toast, Badge, Modal.
    El inventario crece conforme lo piden las historias siguientes (Combobox, Label, Popover,
    Select, Table, NavigationMenu, DropdownMenu); esta spec no congela la lista, pero sí es la
    dueña de la regla para elegir entre primitivos equivalentes.
12. Iconografía: Heroicons.
13. No se usa ninguna herramienta de diseño externa (Figma/Sketch/XD); wireframe y mockup se
    construyen directamente en código Vue + Tailwind.
14. Accesibilidad: se busca contraste razonable (AA básico) en la paleta, sin auditoría formal de
    accesibilidad.
15. Se actualizan retroactivamente las pantallas ya existentes (`/login`, `/forgot-password`,
    `/reset-password`, `/dashboard`) para adoptar el nuevo sistema, no solo pantallas futuras.
16. No existe un logo/marca real todavía; se usa un placeholder de texto (nombre del sistema) como
    identidad visual.
17. Los tokens de diseño (colores, spacing, fuentes) se centralizan en la configuración de
    Tailwind (`@theme` en v4), no dispersos por componente.
18. **(Aclarado antes de implementar)** Paleta "Indigo moderno": primario `#4F46E5` (indigo-600),
    secundario `#7C3AED` (violet-600), neutros slate, éxito `#16A34A`, error `#DC2626`,
    advertencia `#D97706`.
19. **(Aclarado antes de implementar)** De "Alert/Toast" (criterio #2) se implementa solo
    **Alert** (banner estático de info/éxito/error/advertencia); no se construye un sistema de
    Toast/notificaciones flotantes en esta historia.
20. **(Aclarado antes de implementar)** No se hace una ronda separada de aprobación de wireframes
    en gris; se implementa layout + estilo final juntos por pantalla (el wireframe queda
    implícito en la estructura HTML antes de aplicar clases de Tailwind, no como entregable
    revisable aparte).
