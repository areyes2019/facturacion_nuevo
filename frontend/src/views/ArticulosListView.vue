<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  PlusIcon,
  PencilIcon,
  TrashIcon,
  ArrowDownTrayIcon,
  ArrowsRightLeftIcon,
  ChevronDownIcon,
  DocumentArrowUpIcon,
  FolderIcon,
  PhotoIcon,
  ShareIcon,
} from '@heroicons/vue/24/outline'
import {
  useArticulosStore,
  type Articulo,
  type ArticuloFiltroTexto,
  type ArticuloSort,
  type ImportarCsvReporte,
  type ImagenesReporte,
} from '../stores/articulos'
import { useCatalogosStore, type Catalogo } from '../stores/catalogos'
import { extractErrorMessage, mensajeDeFallaDeDescarga } from '../lib/errors'
import { compartirArchivo } from '../lib/compartir'
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '../components/ui/dropdown-menu'

const articulos = useArticulosStore()
const catalogos = useCatalogosStore()
const route = useRoute()
const router = useRouter()

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

/** Mover en lote a otro catálogo (ver 034-filtro-catalogo-y-mover-lote-articulos.md). */
const mostrarMoverLote = ref(false)
const catalogoDestino = ref<number | null>(null)
const moviendoLote = ref(false)
const errorMoverLote = ref<string | null>(null)

/**
 * Compartir la selección como lista de precios en PDF (ver 028-lista-precios-pdf.md).
 *
 * A diferencia de Eliminar y Mover, compartir no cambia nada en el servidor, así que la selección
 * no se limpia al terminar: el usuario puede querer generar la lista de nuevo o seguir ajustando
 * qué artículos lleva.
 */
const UMBRAL_AVISO_LISTA_PRECIOS = 100
const mostrarAvisoListaGrande = ref(false)
const compartiendoLista = ref(false)
const errorCompartirLista = ref<string | null>(null)
const avisoCompartirLista = ref<string | null>(null)

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
 * Las dos cargas masivas son **dos pantallas aparte** (ver 020-imagenes-articulos.md, revisión del
 * 2026-08-18), no los dos pasos de un mismo modal que fueron desde 023.
 *
 * Ninguna hereda nada de la otra: cada una pregunta su propio catálogo y arranca vacía. Un selector
 * que llega con algo puesto obliga a leerlo antes de usarlo; uno vacío obliga a llenarlo, que es más
 * trabajo pero no admite el error de no haberlo mirado.
 */
const mostrarImportar = ref(false)
const catalogoImportar = ref<number | null>(null)
const catalogoImportarInfo = ref<Catalogo | null>(null)

const archivoImportar = ref<File | null>(null)
const importando = ref(false)
const errorImportar = ref<string | null>(null)
const reporteImportar = ref<ImportarCsvReporte | null>(null)

const mostrarImagenes = ref(false)
const catalogoImagenes = ref<number | null>(null)
const catalogoImagenesInfo = ref<Catalogo | null>(null)

const archivosImagenes = ref<File[]>([])
const subiendoImagenes = ref(false)
const progresoImagenes = ref({ enviados: 0, total: 0 })
const errorImagenes = ref<string | null>(null)
const reporteImagenes = ref<ImagenesReporte | null>(null)

/**
 * En un catálogo sin artículos **toda** imagen fallaría por definición, porque el emparejamiento es
 * contra artículos que ya existen (ver 020).
 *
 * Hasta 023 esto bloqueaba la subida. Ahora solo avisa, por dos razones: el conteo puede venir de
 * hace un minuto y el bloqueo apostaría a que el sistema sabe más que quien lo usa, y lo que se
 * pierde por equivocarse es una subida, no un dato — el ZIP sigue en su carpeta y ninguna foto llega
 * a escribirse en el servidor.
 */
const catalogoImagenesVacio = computed(
  () => catalogoImagenesInfo.value !== null && catalogoImagenesInfo.value.articulos_count === 0,
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

onMounted(async () => {
  await restaurarFiltroCatalogoDesdeUrl()
  articulos.fetchList()
})

/**
 * El filtro de catálogo sobrevive a un recargado del navegador reflejándose en la URL
 * (`?catalogo=`, ver revisión de 035-ajustes-tabla-articulos.md), en vez de `localStorage`, para
 * que además el enlace sea compartible.
 *
 * Se valida contra los catálogos del usuario antes de aplicarlo (`CatalogoProveedorController::show`
 * responde 404 ante un id ajeno o inexistente): un id inválido se ignora en silencio, dejando el
 * filtro en "Todos los catálogos" en vez de mandar al servidor un `filtro_catalogo_id` que
 * filtraría el listado a cero resultados sin ninguna explicación.
 */
async function restaurarFiltroCatalogoDesdeUrl() {
  const idTexto = route.query.catalogo
  const id = typeof idTexto === 'string' ? Number(idTexto) : NaN

  if (!Number.isInteger(id) || id <= 0) {
    if (idTexto !== undefined) await limpiarCatalogoDeUrl()
    return
  }

  try {
    await catalogos.fetchOne(id)
    articulos.filtros.catalogoId = id
  } catch {
    await limpiarCatalogoDeUrl()
  }
}

function limpiarCatalogoDeUrl() {
  return router.replace({ query: { ...route.query, catalogo: undefined } })
}

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
 * Columnas de dinero del listado: ordenables por su cabecera
 * (ver 011-precio-proveedor-utilidad.md) y **sin filtro**. Se ordenan, no se acotan: los rangos
 * desde–hasta costaban dos campos por columna en una cabecera que ya estaba llena
 * (ver 025-filtros-columna-listado-articulos.md).
 *
 * "Costo" es el costo total: aparato con descuento + goma. No se agrega columna para el tamaño ni
 * para el desglose, que viven en el formulario, para no revivir el desborde de tabla corregido en
 * 006 (ver 014-costo-elaboracion-goma.md).
 *
 * "Precio distribuidor" es el tercero (ver 033-precio-distribuidor.md): a diferencia de "Costo", no
 * lleva la goma, porque el distribuidor nunca la paga.
 *
 * Las etiquetas son abreviadas ("P Directo", "P Dist") solo en esta tabla, para que quepan en una
 * sola línea (ver 034-filtro-catalogo-y-mover-lote-articulos.md). El nombre completo se queda igual
 * en cualquier otra pantalla del sistema.
 *
 * "Utilidad" es el monto en pesos de la utilidad **directa** del artículo: precio directo menos
 * costo total (ver 035-ajustes-tabla-articulos.md). No es un porcentaje ni la utilidad del
 * distribuidor. No lleva filtro de rango, solo ordenación, igual que las otras tres.
 *
 * "P Directo" muestra el precio **con IVA incluido** (`precio_unitario_con_iva`, ver revisión de
 * 035): es el precio que de verdad paga el público, el que hace falta para tasar precios y ver
 * márgenes de ganancia. Ordenar por su cabecera y su filtro de rango (025) siguen operando sobre el
 * valor sin IVA en el servidor, sin cambio de comportamiento — solo cambió el número visible en la
 * celda. "P Dist" y "Utilidad" no cambiaron: siguen sin IVA.
 *
 * La misma lista dibuja su celda en la fila de cabeceras y en la de filtros, para que no puedan
 * quedar con distinto número de celdas.
 */
const columnasNumericas: { clave: ArticuloSort; etiqueta: string }[] = [
  { clave: 'costo_total', etiqueta: 'Costo' },
  { clave: 'precio_unitario_sin_iva', etiqueta: 'P Directo' },
  { clave: 'precio_distribuidor_sin_iva', etiqueta: 'P Dist' },
  { clave: 'utilidad', etiqueta: 'Utilidad' },
]

/** Un filtro tecleado cambia el estado y recarga con rebote. */
function onFiltro(clave: ArticuloFiltroTexto, valor: string | number) {
  articulos.filtros[clave] = String(valor)
  recargarConRebote()
}

/**
 * El filtro de catálogo recarga de inmediato, sin rebote: un `<select>` no genera una petición
 * por tecla como Nombre y Modelo (ver 034-filtro-catalogo-y-mover-lote-articulos.md).
 *
 * También actualiza la URL con `router.replace()`, no `router.push()` (ver revisión de
 * 035-ajustes-tabla-articulos.md): cambiar de catálogo varias veces no debe llenar el historial del
 * navegador con una entrada por cada uno.
 */
function onFiltroCatalogo(catalogoId: number | null) {
  articulos.filtros.catalogoId = catalogoId
  router.replace({ query: { ...route.query, catalogo: catalogoId?.toString() } })
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

function abrirMoverLote() {
  mostrarMoverLote.value = true
  catalogoDestino.value = null
  errorMoverLote.value = null
}

async function confirmarMoverLote() {
  if (!catalogoDestino.value) return

  moviendoLote.value = true
  errorMoverLote.value = null
  try {
    await articulos.moverLoteCatalogo([...seleccionados.value], catalogoDestino.value)
    mostrarMoverLote.value = false
    await articulos.fetchList(articulos.meta?.current_page ?? 1)
  } catch (err) {
    errorMoverLote.value = extractErrorMessage(err)
  } finally {
    moviendoLote.value = false
  }
}

/**
 * Con más de 100 artículos seleccionados, avisa antes de generar el PDF (puede tardar unos
 * segundos); con 100 o menos, genera de inmediato sin interrumpir el gesto de compartir.
 */
function onCompartirLista() {
  errorCompartirLista.value = null
  avisoCompartirLista.value = null

  if (seleccionados.value.length > UMBRAL_AVISO_LISTA_PRECIOS) {
    mostrarAvisoListaGrande.value = true
    return
  }

  void generarYCompartirLista()
}

async function generarYCompartirLista() {
  mostrarAvisoListaGrande.value = false
  compartiendoLista.value = true
  errorCompartirLista.value = null
  avisoCompartirLista.value = null

  try {
    const blob = await articulos.listaPreciosBlob([...seleccionados.value])
    const nombreArchivo = `lista-precios-${new Date().toISOString().slice(0, 10)}.pdf`
    // Sin texto acompañante: igual que el resto de los PDF de escritorio (cotización, factura),
    // sin texto no hay canal que elegir, así que el respaldo es dejar el archivo descargado, sin
    // abrir WhatsApp por su cuenta.
    const resultado = await compartirArchivo(blob, nombreArchivo)

    if (resultado === 'descargado') {
      avisoCompartirLista.value = 'Lista descargada.'
    }
  } catch (err) {
    errorCompartirLista.value = await mensajeDeFallaDeDescarga(err)
  } finally {
    compartiendoLista.value = false
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

function abrirImportar() {
  mostrarImportar.value = true
  catalogoImportar.value = null
  catalogoImportarInfo.value = null
  reiniciarImportar()
}

function abrirImagenes() {
  mostrarImagenes.value = true
  catalogoImagenes.value = null
  catalogoImagenesInfo.value = null
  reiniciarImagenes()
}

/** Un reporte que sobrevive al cambio de catálogo afirma algo cierto sobre un catálogo que ya no
 * está en pantalla, que es peor que no tener reporte. */
function reiniciarImportar() {
  archivoImportar.value = null
  errorImportar.value = null
  reporteImportar.value = null
}

function reiniciarImagenes() {
  archivosImagenes.value = []
  errorImagenes.value = null
  reporteImagenes.value = null
  progresoImagenes.value = { enviados: 0, total: 0 }
}

/**
 * Solo reinician cuando el catálogo **cambió**, no cada vez que llega el evento: `CatalogoSelect`
 * emite también al montarse y al terminar de cargar sus opciones, y borrar ahí un reporte recién
 * producido dejaría al usuario sin saber qué acaba de pasar.
 */
function onCatalogoImportarSeleccionado(catalogo: Catalogo | null) {
  const cambio = (catalogo?.id ?? null) !== (catalogoImportarInfo.value?.id ?? null)
  catalogoImportarInfo.value = catalogo

  if (cambio) reiniciarImportar()
}

function onCatalogoImagenesSeleccionado(catalogo: Catalogo | null) {
  const cambio = (catalogo?.id ?? null) !== (catalogoImagenesInfo.value?.id ?? null)
  catalogoImagenesInfo.value = catalogo

  if (cambio) reiniciarImagenes()
}

function onArchivoSeleccionado(event: Event) {
  const input = event.target as HTMLInputElement
  archivoImportar.value = input.files?.[0] ?? null
}

function onImagenesSeleccionadas(event: Event) {
  const input = event.target as HTMLInputElement
  archivosImagenes.value = Array.from(input.files ?? [])
}

/** Cómo se nombra el catálogo dentro del texto copiado, que se va a leer lejos de esta pantalla. */
function catalogoEnTexto(catalogo: Catalogo | null): string {
  return catalogo ? `${catalogo.proveedor_nombre_comercial ?? '—'} — ${catalogo.nombre}` : ''
}

/**
 * El reporte es lo único que dice qué quedó pendiente y se pierde al cerrar el modal, así que se
 * puede copiar entero (ver 023-carga-masiva-por-pasos.md). El texto se explica solo: va a terminar
 * pegado en una hoja de cálculo o en un mensaje, lejos de la pantalla que lo produjo.
 */
function textoReporteCsv(reporte: ImportarCsvReporte): string {
  const lineas = [
    `Importación de artículos — ${catalogoEnTexto(catalogoImportarInfo.value)}`,
    `${reporte.importados} artículo(s) importado(s), ${reporte.errores.length} fila(s) con errores.`,
  ]

  for (const error of reporte.errores) {
    lineas.push(`Fila ${error.fila}${error.modelo ? ` (${error.modelo})` : ''}: ${error.motivo}`)
  }

  return lineas.join('\n')
}

function textoReporteImagenes(reporte: ImagenesReporte): string {
  const lineas = [
    `Imágenes — ${catalogoEnTexto(catalogoImagenesInfo.value)}`,
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
  if (!catalogoImagenes.value || archivosImagenes.value.length === 0) return

  subiendoImagenes.value = true
  errorImagenes.value = null
  reporteImagenes.value = null
  progresoImagenes.value = { enviados: 0, total: archivosImagenes.value.length }

  try {
    reporteImagenes.value = await articulos.cargarImagenes(
      catalogoImagenes.value,
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
  if (!catalogoImportar.value || !archivoImportar.value) return

  importando.value = true
  errorImportar.value = null
  reporteImportar.value = null
  try {
    reporteImportar.value = await articulos.importarCsv(
      catalogoImportar.value,
      archivoImportar.value,
    )

    // El conteo de artículos que alimenta el aviso de la otra pantalla no hay que refrescarlo a
    // mano: su `CatalogoSelect` se monta cada vez que ese modal se abre, y pide el listado entonces.
    await articulos.fetchList(1)
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
  <AppLayout>
    <div class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">Artículos</h1>
        <div class="flex flex-wrap gap-2">
          <!-- Un solo botón para las tres operaciones de archivo. Cuatro botones sueltos no caben
               en la barra de un celular, y lo que agrupa a las tres no es la dirección en que viaja
               el archivo, sino que ninguna es aquello a lo que se entra a esta pantalla el resto del
               tiempo (ver 020, revisión del 2026-08-18). "Nuevo artículo" se queda a la vista: es la
               acción de todos los días. -->
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <Button variant="outline" class="gap-1.5" :disabled="exportando">
                <FolderIcon class="size-4" />
                {{ exportando ? 'Exportando...' : 'Archivos' }}
                <ChevronDownIcon class="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-60">
              <DropdownMenuItem class="gap-2" @select="abrirImportar">
                <DocumentArrowUpIcon class="size-4 shrink-0" />
                Importar artículos (CSV)
              </DropdownMenuItem>
              <DropdownMenuItem class="gap-2" @select="abrirImagenes">
                <PhotoIcon class="size-4 shrink-0" />
                Subir imágenes (ZIP)
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem class="gap-2" @select="onExportar">
                <ArrowDownTrayIcon class="size-4 shrink-0" />
                Exportar CSV
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <Button as-child>
            <RouterLink :to="{ name: 'articulos-crear' }">
              <PlusIcon class="size-4" />
              Nuevo artículo
            </RouterLink>
          </Button>
        </div>
      </div>

      <!-- El filtro de catálogo vive aquí, junto al buscador global, no dentro de la tabla
           (ver 035-ajustes-tabla-articulos.md): es la misma casilla de 034, solo cambia de lugar. -->
      <div class="flex flex-wrap items-center gap-2">
        <Input
          v-model="articulos.search"
          placeholder="Buscar por nombre, modelo o proveedor..."
          class="max-w-sm"
          @update:model-value="recargarConRebote"
        />
        <div class="w-64">
          <CatalogoSelect
            :model-value="articulos.filtros.catalogoId"
            incluir-todos
            placeholder="Todos los catálogos"
            @update:model-value="onFiltroCatalogo"
          />
        </div>
      </div>

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
          <Button variant="outline" size="sm" @click="abrirMoverLote">
            <ArrowsRightLeftIcon class="size-4" />
            Mover a catálogo
          </Button>
          <Button
            variant="outline"
            size="sm"
            :disabled="compartiendoLista"
            @click="onCompartirLista"
          >
            <ShareIcon class="size-4" />
            {{ compartiendoLista ? 'Generando...' : 'Compartir Lista' }}
          </Button>
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
      <Alert v-if="errorCompartirLista" variant="destructive">
        <AlertDescription>{{ errorCompartirLista }}</AlertDescription>
      </Alert>
      <Alert v-if="avisoCompartirLista">
        <AlertDescription>{{ avisoCompartirLista }}</AlertDescription>
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
                <!-- Nombre es la única sin ancho: se queda con todo lo que las demás no reclaman,
                     que es donde mejor se aprovecha cada columna que salió de la tabla
                     (ver 025-filtros-columna-listado-articulos.md). -->
                <TableHead>Nombre</TableHead>
                <TableHead class="w-32">Modelo</TableHead>
                <TableHead v-for="columna in columnasNumericas" :key="columna.clave" class="w-24">
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
                   habría que abrir un menú por columna para saber por qué la tabla muestra menos
                   renglones de los esperados (ver 025-filtros-columna-listado-articulos.md). -->
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
                <!-- Las columnas de dinero no llevan filtro: se ordenan desde su cabecera. Su
                     celda va igual, vacía, porque las dos filas se dibujan con la misma lista y
                     deben tener el mismo número de celdas. -->
                <TableHead
                  v-for="columna in columnasNumericas"
                  :key="columna.clave"
                  class="h-auto py-2"
                ></TableHead>
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
                <TableCell class="whitespace-normal">
                  <!-- El nombre es el enlace a la ficha. No hay miniatura en la tabla: las fotos
                       le quitarían al listado la densidad que lo hace útil para trabajar.
                       Se muestra completo, envuelto en las líneas que haga falta, en vez de
                       recortado con elipsis (ver 035-ajustes-tabla-articulos.md). `TableCell` aplica
                       `whitespace-nowrap` por defecto (ver 006); esta celda lo revierte a propósito. -->
                  <button
                    type="button"
                    class="hover:text-primary block w-full text-left font-medium underline-offset-4 hover:underline"
                    @click="articuloDetalle = articulo"
                  >
                    {{ articulo.nombre }}
                  </button>
                </TableCell>
                <TableCell class="truncate" :title="articulo.modelo">{{
                  articulo.modelo
                }}</TableCell>
                <TableCell class="tabular-nums">${{ pesos(articulo.costo_total) }}</TableCell>
                <TableCell class="tabular-nums">
                  ${{ pesos(articulo.precio_unitario_con_iva) }}
                </TableCell>
                <TableCell class="tabular-nums">
                  ${{ pesos(articulo.precio_distribuidor_sin_iva) }}
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

      <!-- Mover en lote (ver 034-filtro-catalogo-y-mover-lote-articulos.md). Sin aviso de
           irreversibilidad: a diferencia de eliminar, es una acción de bajo riesgo y reversible
           con el mismo gesto. -->
      <Dialog :open="mostrarMoverLote" @update:open="(v) => !v && (mostrarMoverLote = false)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Mover artículos</DialogTitle>
            <DialogDescription>
              Mover {{ seleccionados.length }} artículo{{ seleccionados.length === 1 ? '' : 's' }}
              a:
            </DialogDescription>
          </DialogHeader>
          <CatalogoSelect v-model="catalogoDestino" />
          <Alert v-if="errorMoverLote" variant="destructive">
            <AlertDescription>{{ errorMoverLote }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="moviendoLote" @click="mostrarMoverLote = false">
              Cancelar
            </Button>
            <Button :disabled="moviendoLote || !catalogoDestino" @click="confirmarMoverLote">
              {{ moviendoLote ? 'Moviendo...' : 'Mover' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Compartir Lista (ver 028-lista-precios-pdf.md). Solo avisa con selecciones grandes; no
           hay tope que impida generar el PDF, solo un momento para confirmar que puede tardar. -->
      <Dialog
        :open="mostrarAvisoListaGrande"
        @update:open="(v) => !v && (mostrarAvisoListaGrande = false)"
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Lista de precios grande</DialogTitle>
            <DialogDescription>
              Vas a generar una lista con {{ seleccionados.length }} artículos. Puede tardar unos
              segundos en generarse. ¿Continuar?
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" @click="mostrarAvisoListaGrande = false">Cancelar</Button>
            <Button @click="generarYCompartirLista">Continuar</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Pantalla 1: artículos. No sabe nada de la de imágenes: no hereda su catálogo ni ofrece
           continuar hacia ella al terminar. Cerrar aquí es un final completo — hay catálogos que
           son solo servicios y no llevan foto (ver 020). -->
      <Dialog :open="mostrarImportar" @update:open="(v) => !v && (mostrarImportar = false)">
        <DialogContent class="max-h-[90dvh] grid-rows-[auto_auto_minmax(0,1fr)_auto]">
          <DialogHeader>
            <DialogTitle>Importar artículos</DialogTitle>
            <DialogDescription>
              Da de alta artículos en un catálogo a partir de un CSV. Las fotos se suben aparte.
            </DialogDescription>
          </DialogHeader>

          <div class="min-w-0 space-y-1.5">
            <Label>Catálogo</Label>
            <CatalogoSelect
              v-model="catalogoImportar"
              @seleccionado="onCatalogoImportarSeleccionado"
            />
          </div>

          <!-- Cuerpo con scroll propio: un reporte de cien filas rechazadas empujaría el pie del
               modal fuera de la pantalla. -->
          <div
            class="min-w-0 space-y-4 overflow-y-auto pr-1"
            :class="{ 'opacity-60': !catalogoImportar }"
          >
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
              <code>mediana</code>, <code>grande</code> o <code>jumbo</code>, y si la celda va vacía
              el artículo no lleva goma.
            </p>

            <div class="min-w-0 space-y-1.5">
              <Label for="archivo_csv">Archivo CSV</Label>
              <input
                id="archivo_csv"
                :key="`csv-${catalogoImportar ?? 0}`"
                type="file"
                accept=".csv,text/csv"
                :disabled="!catalogoImportar || importando"
                class="border-input w-full min-w-0 rounded-md border px-3 py-1.5 text-sm"
                @change="onArchivoSeleccionado"
              />
            </div>

            <div class="flex justify-end">
              <Button
                :disabled="importando || !catalogoImportar || !archivoImportar"
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
                    <!-- El modelo es lo que conecta la fila rechazada con la foto que se va a quedar
                         sin artículo (ver 023). -->
                    <li v-for="error in reporteImportar.errores" :key="error.fila">
                      Fila {{ error.fila
                      }}<template v-if="error.modelo"> ({{ error.modelo }})</template>:
                      {{ error.motivo }}
                    </li>
                  </ul>
                </template>

                <div class="mt-3">
                  <Button variant="outline" size="sm" @click="copiarReporte('csv')">
                    {{ copiado === 'csv' ? 'Copiado' : 'Copiar reporte' }}
                  </Button>
                </div>
              </AlertDescription>
            </Alert>
          </div>

          <DialogFooter>
            <Button variant="outline" :disabled="importando" @click="mostrarImportar = false">
              Cerrar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Pantalla 2: imágenes. Su selector arranca vacío igual que el de artículos; que no herede
           nada es lo que las vuelve de verdad independientes (ver 020). -->
      <Dialog :open="mostrarImagenes" @update:open="(v) => !v && (mostrarImagenes = false)">
        <DialogContent class="max-h-[90dvh] grid-rows-[auto_auto_minmax(0,1fr)_auto]">
          <DialogHeader>
            <DialogTitle>Subir imágenes</DialogTitle>
            <DialogDescription>
              Cada imagen se asocia sola al artículo cuyo modelo coincida con el nombre del archivo.
            </DialogDescription>
          </DialogHeader>

          <div class="min-w-0 space-y-1.5">
            <Label>Catálogo</Label>
            <CatalogoSelect
              v-model="catalogoImagenes"
              @seleccionado="onCatalogoImagenesSeleccionado"
            />

            <!-- Avisa, no bloquea: el aviso dice lo mismo que decía el candado de 023, pero no
                 necesita tener razón. No menciona cuántas fotos se van a descartar porque con un
                 .zip el navegador no puede saber cuántas trae sin abrirlo (ver 020). -->
            <Alert v-if="catalogoImagenesVacio" variant="warning">
              <AlertDescription>
                <strong>{{ catalogoImagenesInfo?.nombre }} no tiene ningún artículo.</strong>
                Ninguna de estas fotos va a encontrar a quién pertenecer. Empieza por importar los
                artículos.
              </AlertDescription>
            </Alert>
            <p v-else-if="catalogoImagenesInfo" class="text-muted-foreground text-sm">
              {{ catalogoImagenesInfo.articulos_count }} artículo(s) en este catálogo.
            </p>
          </div>

          <div
            class="min-w-0 space-y-4 overflow-y-auto pr-1"
            :class="{ 'opacity-60': !catalogoImagenes }"
          >
            <p class="text-muted-foreground text-sm">
              Se ignoran mayúsculas, acentos y la diferencia entre espacios, guiones y guiones
              bajos:
              <code>a 1234.jpg</code>, <code>A-1234.jpg</code> y <code>A_1234.webp</code> encuentran
              al mismo artículo. Formatos: JPG, PNG y WEBP.
            </p>

            <p class="text-muted-foreground text-sm">
              También puedes subir un <code>.zip</code>, pero debe venir <strong>plano</strong>: si
              trae carpetas dentro se rechaza completo. Comprime la selección de archivos, no la
              carpeta que los contiene.
            </p>

            <div class="min-w-0 space-y-1.5">
              <Label for="archivos_imagenes">Imágenes o archivo ZIP</Label>
              <input
                id="archivos_imagenes"
                :key="`img-${catalogoImagenes ?? 0}`"
                type="file"
                multiple
                accept="image/jpeg,image/png,image/webp,.zip"
                :disabled="!catalogoImagenes || subiendoImagenes"
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
                :disabled="subiendoImagenes || !catalogoImagenes || archivosImagenes.length === 0"
                @click="confirmarImagenes"
              >
                <template v-if="subiendoImagenes">Subiendo...</template>
                <template v-else-if="catalogoImagenesVacio">Subir de todos modos</template>
                <template v-else>Subir imágenes</template>
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
          </div>

          <DialogFooter>
            <Button variant="outline" :disabled="subiendoImagenes" @click="mostrarImagenes = false">
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
