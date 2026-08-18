<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  PlusIcon,
  PencilIcon,
  TrashIcon,
  ArrowUpTrayIcon,
  ArrowDownTrayIcon,
} from '@heroicons/vue/24/outline'
import {
  useArticulosStore,
  type Articulo,
  type ArticuloFiltroTexto,
  type ArticuloSort,
  type ImportarCsvReporte,
  type ImagenesReporte,
} from '../stores/articulos'
import type { Catalogo } from '../stores/catalogos'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import CatalogoSelect from '../components/CatalogoSelect.vue'
import ColumnaOrdenable from '../components/ColumnaOrdenable.vue'
import ArticuloDetalleDialog from '../components/ArticuloDetalleDialog.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '../components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '../components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '../components/ui/dialog'

const articulos = useArticulosStore()

const articuloAEliminar = ref<Articulo | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

/**
 * Selección múltiple (ver 021-mantenimiento-articulos-catalogos.md). Abarca **solo la página
 * visible**: sostenerla a través de páginas y filtros obligaría a decidir qué pasa cuando un
 * artículo seleccionado deja de coincidir con la búsqueda, y a mostrar en algún lado qué hay marcado
 * que no se ve. El caso de uso —"estos de aquí sobran"— no lo pide.
 */
const seleccionados = ref<number[]>([])
const mostrarEliminarLote = ref(false)
const eliminandoLote = ref(false)
const errorEliminarLote = ref<string | null>(null)

// Cualquier cosa que cambie las filas en pantalla vacía la selección.
watch(
  () => articulos.items,
  () => (seleccionados.value = []),
)

const todosSeleccionados = computed(
  () => articulos.items.length > 0 && seleccionados.value.length === articulos.items.length,
)

const algunoSeleccionado = computed(
  () => seleccionados.value.length > 0 && !todosSeleccionados.value,
)

function alternarTodos(event: Event) {
  const marcado = (event.target as HTMLInputElement).checked
  seleccionados.value = marcado ? articulos.items.map((articulo) => articulo.id) : []
}

const exportando = ref(false)
const errorExportar = ref<string | null>(null)

const articuloDetalle = ref<Articulo | null>(null)

/**
 * Carga masiva en un solo modal por pasos (ver 023-carga-masiva-por-pasos.md).
 *
 * El catálogo se elige **una sola vez** y manda sobre los dos pasos: importar el CSV en un catálogo
 * y las fotos en otro nunca es lo que se quiso hacer, y era el error que abría tener un selector
 * dentro de cada modal.
 */
const mostrarCarga = ref(false)
const catalogoCarga = ref<number | null>(null)
const catalogoCargaInfo = ref<Catalogo | null>(null)
const catalogoSelect = ref<{ recargar: () => Promise<void> } | null>(null)
const paso2 = ref<HTMLElement | null>(null)

const archivoImportar = ref<File | null>(null)
const importando = ref(false)
const errorImportar = ref<string | null>(null)
const reporteImportar = ref<ImportarCsvReporte | null>(null)

const archivosImagenes = ref<File[]>([])
const subiendoImagenes = ref(false)
const progresoImagenes = ref({ enviados: 0, total: 0 })
const errorImagenes = ref<string | null>(null)
const reporteImagenes = ref<ImagenesReporte | null>(null)

/**
 * En un catálogo sin artículos **toda** imagen fallaría por definición, porque el emparejamiento es
 * contra artículos que ya existen (ver 020). Por eso el paso 2 se bloquea en vez de advertir: no hay
 * ningún caso legítimo del otro lado al que dejar pasar.
 */
const paso2Bloqueado = computed(
  () => catalogoCargaInfo.value !== null && catalogoCargaInfo.value.articulos_count === 0,
)

/**
 * Doscientos motivos idénticos describen doscientas veces el síntoma y ninguna la causa probable,
 * que casi siempre es una sola: el catálogo equivocado o los nombres de archivo.
 */
const ningunaEmparejo = computed(
  () =>
    reporteImagenes.value !== null &&
    reporteImagenes.value.asociadas === 0 &&
    reporteImagenes.value.errores.length > 0,
)

onMounted(() => articulos.fetchList())

/**
 * Vuelve a pedir el listado tras una pausa, para no lanzar una petición por tecla.
 *
 * El buscador global y los filtros de columna comparten el mismo temporizador a propósito: escribir
 * en dos de ellos seguidos es una sola intención y merece una sola consulta
 * (ver 025-filtros-columna-listado-articulos.md).
 */
let recargarTimeout: ReturnType<typeof setTimeout>
function recargarConRebote() {
  clearTimeout(recargarTimeout)
  recargarTimeout = setTimeout(() => articulos.fetchList(1), 300)
}

/**
 * Columnas numéricas del listado: ordenables (ver 011-precio-proveedor-utilidad.md) y filtrables por
 * rango desde–hasta (ver 025-filtros-columna-listado-articulos.md).
 *
 * "Costo" es el costo total: aparato con descuento + goma. No se agrega columna para el tamaño ni
 * para el desglose, que viven en el formulario, para no revivir el desborde de tabla corregido en
 * 006 (ver 014-costo-elaboracion-goma.md).
 *
 * La misma lista dibuja la fila de cabeceras y la de filtros, para que no puedan quedar con distinto
 * número de celdas.
 */
const columnasNumericas: {
  clave: ArticuloSort
  etiqueta: string
  min: ArticuloFiltroTexto
  max: ArticuloFiltroTexto
}[] = [
  { clave: 'costo_total', etiqueta: 'Costo', min: 'costoMin', max: 'costoMax' },
  {
    clave: 'precio_unitario_sin_iva',
    etiqueta: 'Precio de venta',
    min: 'precioMin',
    max: 'precioMax',
  },
  { clave: 'utilidad', etiqueta: 'Utilidad', min: 'utilidadMin', max: 'utilidadMax' },
]

/** Un filtro tecleado cambia el estado y recarga con rebote. */
function onFiltro(clave: ArticuloFiltroTexto, valor: string | number) {
  articulos.filtros[clave] = String(valor)
  recargarConRebote()
}

/**
 * Opciones del filtro de catálogo. Se piden aquí y no al store de catálogos para no pisarle el
 * estado a su propio listado, que está paginado de 15 en 15.
 */
const catalogosFiltro = ref<Catalogo[]>([])

onMounted(async () => {
  const { data } = await http.get('/catalogos-proveedor', { params: { per_page: 100 } })
  catalogosFiltro.value = data.data
})

const TODOS_LOS_CATALOGOS = 'todos'

/** El selector consulta de inmediato: es una elección, no algo que se escribe. */
function onFiltroCatalogo(valor: string) {
  articulos.filtros.catalogoId = valor === TODOS_LOS_CATALOGOS ? null : Number(valor)
  articulos.fetchList(1)
}

const totalFiltrado = computed(() => articulos.meta?.total ?? 0)

function pesos(valor: number): string {
  return valor.toFixed(2)
}

function irAPagina(pagina: number) {
  articulos.fetchList(pagina)
}

function abrirEliminar(articulo: Articulo) {
  articuloAEliminar.value = articulo
  errorEliminar.value = null
}

function cerrarEliminar() {
  articuloAEliminar.value = null
  errorEliminar.value = null
}

async function confirmarEliminar() {
  if (!articuloAEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await articulos.remove(articuloAEliminar.value.id)
    articuloAEliminar.value = null
  } catch (err) {
    errorEliminar.value = extractErrorMessage(err)
  } finally {
    eliminando.value = false
  }
}

async function confirmarEliminarLote() {
  eliminandoLote.value = true
  errorEliminarLote.value = null
  try {
    await articulos.removeLote([...seleccionados.value])
    mostrarEliminarLote.value = false
    await articulos.fetchList(articulos.meta?.current_page ?? 1)
  } catch (err) {
    errorEliminarLote.value = extractErrorMessage(err)
  } finally {
    eliminandoLote.value = false
  }
}

async function onExportar() {
  exportando.value = true
  errorExportar.value = null
  try {
    await articulos.exportarCsv()
  } catch (err) {
    errorExportar.value = extractErrorMessage(err)
  } finally {
    exportando.value = false
  }
}

function abrirCarga() {
  mostrarCarga.value = true
  catalogoCarga.value = null
  catalogoCargaInfo.value = null
  reiniciarPasos()
}

function cerrarCarga() {
  mostrarCarga.value = false
}

/** Un reporte que sobrevive al cambio de catálogo afirma algo cierto sobre un catálogo que ya no
 * está en pantalla, que es peor que no tener reporte. */
function reiniciarPasos() {
  archivoImportar.value = null
  errorImportar.value = null
  reporteImportar.value = null
  archivosImagenes.value = []
  errorImagenes.value = null
  reporteImagenes.value = null
  progresoImagenes.value = { enviados: 0, total: 0 }
}

/**
 * Solo reinicia cuando el catálogo **cambió**, no cada vez que llega el evento: releer el conteo
 * tras importar el CSV vuelve a emitir el mismo catálogo, y borrar ahí el reporte recién producido
 * dejaría al usuario sin saber qué acaba de pasar.
 */
function onCatalogoSeleccionado(catalogo: Catalogo | null) {
  const cambio = (catalogo?.id ?? null) !== (catalogoCargaInfo.value?.id ?? null)
  catalogoCargaInfo.value = catalogo

  if (cambio) reiniciarPasos()
}

function onArchivoSeleccionado(event: Event) {
  const input = event.target as HTMLInputElement
  archivoImportar.value = input.files?.[0] ?? null
}

function onImagenesSeleccionadas(event: Event) {
  const input = event.target as HTMLInputElement
  archivosImagenes.value = Array.from(input.files ?? [])
}

/** Lleva la atención al paso 2 desde el reporte del paso 1, con el catálogo ya puesto porque nunca
 * dejó de estarlo. */
function irAPaso2() {
  paso2.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

/** Cómo se nombra el catálogo dentro del texto copiado, que se va a leer lejos de esta pantalla. */
const catalogoEnTexto = computed(() =>
  catalogoCargaInfo.value
    ? `${catalogoCargaInfo.value.proveedor_nombre_comercial ?? '—'} — ${catalogoCargaInfo.value.nombre}`
    : '',
)

/**
 * El reporte es lo único que dice qué quedó pendiente y se pierde al cerrar el modal, así que se
 * puede copiar entero (ver 023-carga-masiva-por-pasos.md). El texto se explica solo: va a terminar
 * pegado en una hoja de cálculo o en un mensaje, lejos de la pantalla que lo produjo.
 */
function textoReporteCsv(reporte: ImportarCsvReporte): string {
  const lineas = [
    `Importación de artículos — ${catalogoEnTexto.value}`,
    `${reporte.importados} artículo(s) importado(s), ${reporte.errores.length} fila(s) con errores.`,
  ]

  for (const error of reporte.errores) {
    lineas.push(`Fila ${error.fila}${error.modelo ? ` (${error.modelo})` : ''}: ${error.motivo}`)
  }

  return lineas.join('\n')
}

function textoReporteImagenes(reporte: ImagenesReporte): string {
  const lineas = [
    `Imágenes — ${catalogoEnTexto.value}`,
    `${reporte.asociadas} imagen(es) asociada(s), ${reporte.errores.length} archivo(s) sin asociar.`,
  ]

  for (const error of reporte.errores) {
    lineas.push(`${error.archivo}: ${error.motivo}`)
  }

  return lineas.join('\n')
}

const copiado = ref<'csv' | 'imagenes' | null>(null)
let copiadoTimeout: ReturnType<typeof setTimeout>

async function copiarReporte(cual: 'csv' | 'imagenes') {
  const texto =
    cual === 'csv'
      ? reporteImportar.value && textoReporteCsv(reporteImportar.value)
      : reporteImagenes.value && textoReporteImagenes(reporteImagenes.value)

  if (!texto) return

  await navigator.clipboard.writeText(texto)

  copiado.value = cual
  clearTimeout(copiadoTimeout)
  copiadoTimeout = setTimeout(() => (copiado.value = null), 2000)
}

async function confirmarImagenes() {
  if (!catalogoCarga.value || archivosImagenes.value.length === 0) return

  subiendoImagenes.value = true
  errorImagenes.value = null
  reporteImagenes.value = null
  progresoImagenes.value = { enviados: 0, total: archivosImagenes.value.length }

  try {
    reporteImagenes.value = await articulos.cargarImagenes(
      catalogoCarga.value,
      archivosImagenes.value,
      (enviados, total) => (progresoImagenes.value = { enviados, total }),
    )
    await articulos.fetchList(articulos.meta?.current_page ?? 1)
  } catch (err) {
    errorImagenes.value = extractErrorMessage(err)
  } finally {
    subiendoImagenes.value = false
  }
}

async function confirmarImportar() {
  if (!catalogoCarga.value || !archivoImportar.value) return

  importando.value = true
  errorImportar.value = null
  reporteImportar.value = null
  try {
    reporteImportar.value = await articulos.importarCsv(catalogoCarga.value, archivoImportar.value)
    await articulos.fetchList(1)

    // El catálogo que estaba vacío deja de estarlo aquí mismo: sin releer el conteo, quien acaba de
    // importar se encontraría el paso 2 bloqueado por un dato de hace treinta segundos.
    await catalogoSelect.value?.recargar()
  } catch (err) {
    errorImportar.value = extractErrorMessage(err)
  } finally {
    importando.value = false
  }
}
</script>

<template>
  <!-- Listado denso: nueve columnas y una fila de filtros no caben en el ancho de lectura del resto
       del sistema (ver 025-filtros-columna-listado-articulos.md). -->
  <AppLayout ancho="amplio">
    <div class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">Artículos</h1>
        <div class="flex flex-wrap gap-2">
          <Button variant="outline" :disabled="exportando" @click="onExportar">
            <ArrowDownTrayIcon class="size-4" />
            {{ exportando ? 'Exportando...' : 'Exportar CSV' }}
          </Button>
          <Button variant="outline" @click="abrirCarga">
            <ArrowUpTrayIcon class="size-4" />
            Carga masiva
          </Button>
          <Button as-child>
            <RouterLink :to="{ name: 'articulos-crear' }">
              <PlusIcon class="size-4" />
              Nuevo artículo
            </RouterLink>
          </Button>
        </div>
      </div>

      <Input
        v-model="articulos.search"
        placeholder="Buscar por nombre, modelo o proveedor..."
        class="max-w-sm"
        @update:model-value="recargarConRebote"
      />

      <!-- Solo con filtros de columna puestos: sin ellos sería una línea permanente que no dice
           nada. El buscador global no cuenta ni se limpia aquí, porque tiene su propia caja
           (ver 025-filtros-columna-listado-articulos.md). -->
      <div
        v-if="articulos.hayFiltros"
        class="bg-muted flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-2"
      >
        <span class="text-muted-foreground text-sm">
          {{ totalFiltrado }} artículo{{ totalFiltrado === 1 ? '' : 's' }} con los filtros aplicados
        </span>
        <Button variant="outline" size="sm" @click="articulos.limpiarFiltros()">
          Limpiar filtros
        </Button>
      </div>

      <!-- Sin nada marcado no aparece, para no dejar en pantalla un botón permanentemente
           deshabilitado (ver 021-mantenimiento-articulos-catalogos.md). -->
      <div
        v-if="seleccionados.length > 0"
        class="bg-muted flex flex-wrap items-center justify-between gap-2 rounded-md px-3 py-2"
      >
        <span class="text-foreground text-sm font-medium">
          {{ seleccionados.length }} seleccionado{{ seleccionados.length === 1 ? '' : 's' }}
        </span>
        <div class="flex gap-2">
          <Button variant="outline" size="sm" @click="seleccionados = []">Quitar selección</Button>
          <Button variant="destructive" size="sm" @click="mostrarEliminarLote = true">
            <TrashIcon class="size-4" />
            Eliminar
          </Button>
        </div>
      </div>

      <Alert v-if="articulos.error" variant="destructive">
        <AlertDescription>{{ articulos.error }}</AlertDescription>
      </Alert>
      <Alert v-if="errorExportar" variant="destructive">
        <AlertDescription>{{ errorExportar }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <!-- `table-fixed`: los anchos los mandan las clases de la primera fila y ningún contenido
               puede ensanchar la tabla. Es lo que garantiza que un nombre largo se recorte en vez de
               empujar los botones de acciones fuera de la vista. -->
          <Table class="table-fixed">
            <TableHeader>
              <TableRow>
                <TableHead class="w-10">
                  <input
                    type="checkbox"
                    class="border-input size-4 rounded"
                    aria-label="Seleccionar todos los artículos de esta página"
                    :checked="todosSeleccionados"
                    :indeterminate="algunoSeleccionado"
                    :disabled="articulos.items.length === 0"
                    @change="alternarTodos"
                  />
                </TableHead>
                <!-- Nombre es la única sin ancho: se queda con lo que sobre, incluido el que dejó
                     libre la columna del número interno, que es donde más se nota
                     (ver 025-filtros-columna-listado-articulos.md). -->
                <TableHead>Nombre</TableHead>
                <TableHead class="w-32">Modelo</TableHead>
                <TableHead class="w-44">Catálogo</TableHead>
                <TableHead
                  v-for="columna in columnasNumericas"
                  :key="columna.clave"
                  class="w-40 whitespace-normal"
                >
                  <ColumnaOrdenable
                    :etiqueta="columna.etiqueta"
                    :activa="articulos.sort === columna.clave"
                    :direccion="articulos.direction"
                    @ordenar="articulos.toggleSort(columna.clave)"
                  />
                </TableHead>
                <TableHead class="w-24 text-right">Acciones</TableHead>
              </TableRow>

              <!-- Fila de filtros. Siempre visibles y no detrás de un menú por columna: escondidos
                   habría que abrir seis menús para saber por qué la tabla muestra menos renglones de
                   los esperados (ver 025-filtros-columna-listado-articulos.md). -->
              <TableRow class="hover:bg-transparent">
                <TableHead class="h-auto py-2"></TableHead>
                <TableHead class="h-auto py-2">
                  <Input
                    :model-value="articulos.filtros.nombre"
                    placeholder="Contiene..."
                    aria-label="Filtrar por nombre"
                    class="h-8 px-2 text-xs"
                    @update:model-value="(v) => onFiltro('nombre', v)"
                  />
                </TableHead>
                <TableHead class="h-auto py-2">
                  <Input
                    :model-value="articulos.filtros.modelo"
                    placeholder="Contiene..."
                    aria-label="Filtrar por modelo"
                    class="h-8 px-2 text-xs"
                    @update:model-value="(v) => onFiltro('modelo', v)"
                  />
                </TableHead>
                <TableHead class="h-auto py-2">
                  <!-- Selector y no texto libre: los catálogos son un conjunto cerrado y corto, y
                       escribir el nombre a mano solo abre la puerta a no encontrar nada por una
                       tilde. -->
                  <Select
                    :model-value="articulos.filtros.catalogoId?.toString() ?? TODOS_LOS_CATALOGOS"
                    @update:model-value="(v) => onFiltroCatalogo(String(v))"
                  >
                    <!-- `min-w-0` en el disparador y en su valor: sin eso, "Proveedor — Catálogo de
                         nombre largo" ensancha el control por encima de su columna. -->
                    <SelectTrigger
                      class="h-8 w-full min-w-0 text-xs *:min-w-0"
                      aria-label="Filtrar por catálogo"
                    >
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem :value="TODOS_LOS_CATALOGOS">Todos los catálogos</SelectItem>
                      <SelectItem
                        v-for="opcion in catalogosFiltro"
                        :key="opcion.id"
                        :value="opcion.id.toString()"
                      >
                        {{ opcion.proveedor_nombre_comercial ?? '—' }} — {{ opcion.nombre }}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </TableHead>
                <!-- Rango desde–hasta, con cada extremo independiente: en dinero nadie busca un
                     valor exacto, busca un tramo, y la mitad de las veces con un solo número.
                     Son campos de texto y no `number`: las flechitas del control nativo se comen un
                     tercio de una celda tan angosta, y lo que no sea un número el backend ya lo
                     ignora en silencio (ver 025-filtros-columna-listado-articulos.md). -->
                <TableHead
                  v-for="columna in columnasNumericas"
                  :key="columna.clave"
                  class="h-auto py-2"
                >
                  <div class="flex items-center gap-1">
                    <Input
                      :model-value="articulos.filtros[columna.min]"
                      inputmode="decimal"
                      placeholder="min"
                      :aria-label="`${columna.etiqueta} mínimo`"
                      class="h-8 px-2 text-xs"
                      @update:model-value="(v) => onFiltro(columna.min, v)"
                    />
                    <span class="text-muted-foreground text-xs">–</span>
                    <Input
                      :model-value="articulos.filtros[columna.max]"
                      inputmode="decimal"
                      placeholder="max"
                      :aria-label="`${columna.etiqueta} máximo`"
                      class="h-8 px-2 text-xs"
                      @update:model-value="(v) => onFiltro(columna.max, v)"
                    />
                  </div>
                </TableHead>
                <TableHead class="h-auto py-2"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!articulos.loading && articulos.items.length === 0">
                <TableCell colspan="8" class="text-muted-foreground py-10 text-center">
                  {{
                    articulos.hayFiltros || articulos.search
                      ? 'Ningún artículo coincide con los filtros aplicados.'
                      : 'No hay artículos registrados todavía.'
                  }}
                </TableCell>
              </TableRow>
              <TableRow v-for="articulo in articulos.items" :key="articulo.id">
                <TableCell>
                  <input
                    v-model="seleccionados"
                    type="checkbox"
                    class="border-input size-4 rounded"
                    :value="articulo.id"
                    :aria-label="`Seleccionar ${articulo.nombre}`"
                  />
                </TableCell>
                <TableCell>
                  <!-- El nombre es el enlace a la ficha. No hay miniatura en la tabla: las fotos
                       le quitarían al listado la densidad que lo hace útil para trabajar.
                       Se recorta con elipsis porque con `table-fixed` un nombre largo se saldría de
                       su columna en vez de ensancharla; el `title` muestra el completo. -->
                  <button
                    type="button"
                    class="hover:text-primary block w-full truncate text-left font-medium underline-offset-4 hover:underline"
                    :title="articulo.nombre"
                    @click="articuloDetalle = articulo"
                  >
                    {{ articulo.nombre }}
                  </button>
                </TableCell>
                <TableCell class="truncate" :title="articulo.modelo">{{
                  articulo.modelo
                }}</TableCell>
                <TableCell truncate :title="articulo.catalogo_nombre ?? undefined">
                  {{ articulo.catalogo_nombre ?? '—' }}
                </TableCell>
                <TableCell class="tabular-nums">${{ pesos(articulo.costo_total) }}</TableCell>
                <TableCell class="tabular-nums">
                  ${{ pesos(articulo.precio_unitario_sin_iva) }}
                </TableCell>
                <TableCell class="tabular-nums">${{ pesos(articulo.utilidad) }}</TableCell>
                <!-- Los botones van en un `div` y no en el propio `td`: un `display:flex` sobre la
                     celda la saca del algoritmo de la tabla y deja de respetar el ancho de su
                     columna, que es justo lo que los desbordaba. -->
                <TableCell class="text-right">
                  <div class="flex justify-end gap-2">
                    <Button as-child variant="outline" size="icon-sm">
                      <RouterLink :to="{ name: 'articulos-editar', params: { id: articulo.id } }">
                        <PencilIcon class="size-4" />
                        <span class="sr-only">Editar</span>
                      </RouterLink>
                    </Button>
                    <Button variant="outline" size="icon-sm" @click="abrirEliminar(articulo)">
                      <TrashIcon class="size-4" />
                      <span class="sr-only">Eliminar</span>
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div v-if="articulos.meta && articulos.meta.last_page > 1" class="flex justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="articulos.meta.current_page <= 1"
          @click="irAPagina(articulos.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ articulos.meta.current_page }} de {{ articulos.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="articulos.meta.current_page >= articulos.meta.last_page"
          @click="irAPagina(articulos.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="articuloAEliminar !== null" @update:open="(v) => !v && cerrarEliminar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar artículo</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar "{{ articuloAEliminar?.nombre }}"? Podrás recuperarlo
              solo por soporte técnico.
            </DialogDescription>
          </DialogHeader>
          <Alert v-if="errorEliminar" variant="destructive">
            <AlertDescription>{{ errorEliminar }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="eliminando" @click="cerrarEliminar">
              Cancelar
            </Button>
            <Button variant="destructive" :disabled="eliminando" @click="confirmarEliminar">
              Eliminar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarEliminarLote" @update:open="(v) => !v && (mostrarEliminarLote = false)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar artículos</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar {{ seleccionados.length }} artículo{{
                seleccionados.length === 1 ? '' : 's'
              }}? Podrás recuperarlos solo por soporte técnico.
            </DialogDescription>
          </DialogHeader>
          <Alert v-if="errorEliminarLote" variant="destructive">
            <AlertDescription>{{ errorEliminarLote }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button
              variant="outline"
              :disabled="eliminandoLote"
              @click="mostrarEliminarLote = false"
            >
              Cancelar
            </Button>
            <Button variant="destructive" :disabled="eliminandoLote" @click="confirmarEliminarLote">
              {{ eliminandoLote ? 'Eliminando...' : 'Eliminar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarCarga" @update:open="(v) => !v && cerrarCarga()">
        <DialogContent class="max-h-[90dvh] grid-rows-[auto_auto_minmax(0,1fr)_auto]">
          <DialogHeader>
            <DialogTitle>Carga masiva</DialogTitle>
            <DialogDescription>
              Elige el catálogo y sigue los dos pasos en orden: primero los artículos, después sus
              fotos.
            </DialogDescription>
          </DialogHeader>

          <!-- Fuera de los pasos y siempre a la vista: el catálogo manda sobre los dos (ver 023). -->
          <div class="min-w-0 space-y-1.5">
            <Label>Catálogo</Label>
            <CatalogoSelect
              ref="catalogoSelect"
              v-model="catalogoCarga"
              @seleccionado="onCatalogoSeleccionado"
            />
            <p class="text-muted-foreground text-sm">
              <template v-if="catalogoCargaInfo">
                {{ catalogoCargaInfo.articulos_count }} artículo(s) en este catálogo.
              </template>
              <template v-else>Los dos pasos trabajan sobre el catálogo que elijas aquí.</template>
            </p>
          </div>

          <!-- Cuerpo con scroll propio: dos reportes largos a la vez empujarían el pie del modal
               fuera de la pantalla (ver 023). -->
          <div class="min-w-0 space-y-6 overflow-y-auto pr-1">
            <section class="min-w-0 space-y-4" :class="{ 'opacity-60': !catalogoCarga }">
              <h3 class="text-foreground text-sm font-semibold">Paso 1 — Artículos (CSV)</h3>

              <p class="text-muted-foreground text-sm">
                Todas las filas se dan de alta en el catálogo elegido (y por lo tanto en su
                proveedor). El CSV debe tener las columnas:
              </p>

              <code
                class="bg-muted block w-full min-w-0 overflow-x-auto rounded-md px-3 py-2 text-xs whitespace-nowrap"
              >
                nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje,tamano_goma
              </code>

              <p class="text-muted-foreground text-sm">
                La columna <code>utilidad_porcentaje</code> es opcional: si la celda va vacía, el
                artículo hereda el porcentaje de utilidad del catálogo seleccionado.
              </p>

              <p class="text-muted-foreground text-sm">
                La columna <code>tamano_goma</code> también es opcional: acepta <code>chica</code>,
                <code>mediana</code> o <code>grande</code>, y si la celda va vacía el artículo no
                lleva goma.
              </p>

              <div class="min-w-0 space-y-1.5">
                <Label for="archivo_csv">Archivo CSV</Label>
                <input
                  id="archivo_csv"
                  :key="`csv-${catalogoCarga ?? 0}`"
                  type="file"
                  accept=".csv,text/csv"
                  :disabled="!catalogoCarga || importando"
                  class="border-input text-sm w-full min-w-0 rounded-md border px-3 py-1.5"
                  @change="onArchivoSeleccionado"
                />
              </div>

              <div class="flex justify-end">
                <Button
                  :disabled="importando || !catalogoCarga || !archivoImportar"
                  @click="confirmarImportar"
                >
                  {{ importando ? 'Importando...' : 'Importar artículos' }}
                </Button>
              </div>

              <Alert v-if="errorImportar" variant="destructive">
                <AlertDescription>{{ errorImportar }}</AlertDescription>
              </Alert>

              <Alert v-if="reporteImportar">
                <AlertDescription>
                  {{ reporteImportar.importados }} artículo(s) importado(s).
                  <template v-if="reporteImportar.errores.length > 0">
                    {{ reporteImportar.errores.length }} fila(s) con errores:
                    <ul class="mt-1 max-h-48 list-disc overflow-y-auto pl-5">
                      <!-- El modelo es lo que conecta la fila rechazada con la foto que se va a
                           quedar sin artículo (ver 023). -->
                      <li v-for="error in reporteImportar.errores" :key="error.fila">
                        Fila {{ error.fila
                        }}<template v-if="error.modelo"> ({{ error.modelo }})</template>:
                        {{ error.motivo }}
                      </li>
                    </ul>
                  </template>

                  <div class="mt-3 flex flex-wrap gap-2">
                    <!-- Un ofrecimiento, no un paso pendiente: hay artículos que no llevan foto
                         (servicios como "Maquila de sellos") y catálogos que son solo de esos. -->
                    <Button
                      v-if="reporteImportar.importados > 0"
                      variant="outline"
                      size="sm"
                      @click="irAPaso2"
                    >
                      Continuar con las imágenes →
                    </Button>
                    <Button variant="outline" size="sm" @click="copiarReporte('csv')">
                      {{ copiado === 'csv' ? 'Copiado' : 'Copiar reporte' }}
                    </Button>
                  </div>
                </AlertDescription>
              </Alert>
            </section>

            <section
              ref="paso2"
              class="min-w-0 space-y-4"
              :class="{ 'opacity-60': !catalogoCarga || paso2Bloqueado }"
            >
              <h3 class="text-foreground text-sm font-semibold">Paso 2 — Imágenes</h3>

              <!-- En un catálogo vacío toda imagen fallaría por definición, así que se bloquea en
                   vez de advertir (ver 023). -->
              <Alert v-if="paso2Bloqueado" variant="warning">
                <AlertDescription>
                  Este catálogo todavía no tiene artículos. Empieza por el paso 1.
                </AlertDescription>
              </Alert>

              <p class="text-muted-foreground text-sm">
                Cada imagen se asocia sola al artículo cuyo modelo coincida con el nombre del
                archivo. Se ignoran mayúsculas, acentos y la diferencia entre espacios, guiones y
                guiones bajos:
                <code>a 1234.jpg</code>, <code>A-1234.jpg</code> y <code>A_1234.webp</code>
                encuentran al mismo artículo. Formatos: JPG, PNG y WEBP.
              </p>

              <p class="text-muted-foreground text-sm">
                También puedes subir un <code>.zip</code>, pero debe venir <strong>plano</strong>:
                si trae carpetas dentro se rechaza completo. Comprime la selección de archivos, no
                la carpeta que los contiene.
              </p>

              <div class="min-w-0 space-y-1.5">
                <Label for="archivos_imagenes">Imágenes o archivo ZIP</Label>
                <input
                  id="archivos_imagenes"
                  :key="`img-${catalogoCarga ?? 0}`"
                  type="file"
                  multiple
                  accept="image/jpeg,image/png,image/webp,.zip"
                  :disabled="!catalogoCarga || paso2Bloqueado || subiendoImagenes"
                  class="border-input w-full min-w-0 rounded-md border px-3 py-1.5 text-sm"
                  @change="onImagenesSeleccionadas"
                />
                <p v-if="archivosImagenes.length > 0" class="text-muted-foreground text-sm">
                  {{ archivosImagenes.length }} archivo(s) seleccionado(s).
                </p>
              </div>

              <!-- Una carga de 300 fotos son 15 peticiones seguidas; sin barra no habría forma de
                   distinguirla de un cuelgue. -->
              <div v-if="subiendoImagenes && progresoImagenes.total > 0" class="space-y-1.5">
                <div class="bg-muted h-2 w-full overflow-hidden rounded-full">
                  <div
                    class="bg-primary h-full transition-all"
                    :style="{
                      width: `${Math.round((progresoImagenes.enviados / progresoImagenes.total) * 100)}%`,
                    }"
                  />
                </div>
                <p class="text-muted-foreground text-sm">
                  {{ progresoImagenes.enviados }} de {{ progresoImagenes.total }} archivos enviados.
                </p>
              </div>

              <div class="flex justify-end">
                <Button
                  :disabled="
                    subiendoImagenes ||
                    !catalogoCarga ||
                    paso2Bloqueado ||
                    archivosImagenes.length === 0
                  "
                  @click="confirmarImagenes"
                >
                  {{ subiendoImagenes ? 'Subiendo...' : 'Subir imágenes' }}
                </Button>
              </div>

              <Alert v-if="errorImagenes" variant="destructive">
                <AlertDescription>{{ errorImagenes }}</AlertDescription>
              </Alert>

              <Alert v-if="reporteImagenes">
                <AlertDescription>
                  <!-- Doscientos motivos idénticos describen el síntoma doscientas veces y la causa
                       probable ninguna (ver 023). -->
                  <p v-if="ningunaEmparejo" class="mb-2 font-medium">
                    Ninguna de las {{ reporteImagenes.errores.length }} imágenes encontró artículo.
                    Revisa que el catálogo sea el correcto y que el nombre de cada archivo coincida
                    con el modelo del artículo.
                  </p>

                  {{ reporteImagenes.asociadas }} imagen(es) asociada(s).
                  <template v-if="reporteImagenes.errores.length > 0">
                    {{ reporteImagenes.errores.length }} archivo(s) sin asociar:
                    <ul class="mt-1 max-h-48 list-disc overflow-y-auto pl-5">
                      <li
                        v-for="(error, i) in reporteImagenes.errores"
                        :key="`${error.archivo}-${i}`"
                      >
                        {{ error.archivo }}: {{ error.motivo }}
                      </li>
                    </ul>
                  </template>

                  <div class="mt-3">
                    <Button variant="outline" size="sm" @click="copiarReporte('imagenes')">
                      {{ copiado === 'imagenes' ? 'Copiado' : 'Copiar reporte' }}
                    </Button>
                  </div>
                </AlertDescription>
              </Alert>
            </section>
          </div>

          <DialogFooter>
            <Button
              variant="outline"
              :disabled="importando || subiendoImagenes"
              @click="cerrarCarga"
            >
              Cerrar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ArticuloDetalleDialog
        :articulo="articuloDetalle"
        @update:open="(v) => !v && (articuloDetalle = null)"
      />
    </div>
  </AppLayout>
</template>
