<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { PlusIcon, EyeIcon, PencilIcon, TrashIcon, ShareIcon } from '@heroicons/vue/24/outline'
import { useFacturasStore, type Factura, type EstadoFactura } from '../stores/facturas'
import { extractErrorMessage } from '../lib/errors'
import { puedeCompartirArchivos, type ArchivoCompartible } from '../lib/compartir'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Badge } from '../components/ui/badge'
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

const facturas = useFacturasStore()

const facturaAEliminar = ref<Factura | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

onMounted(() => facturas.fetchList())

let buscarTimeout: ReturnType<typeof setTimeout>
function onBuscar() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => facturas.fetchList(1), 300)
}

function onFiltrarEstado() {
  facturas.fetchList(1)
}

function irAPagina(pagina: number) {
  facturas.fetchList(pagina)
}

function estadoVariant(estado: EstadoFactura) {
  return {
    timbrada: 'success',
    pendiente: 'warning',
    cancelada: 'destructive',
    borrador: 'secondary',
  }[estado] as 'success' | 'warning' | 'destructive' | 'secondary'
}

// Compartir el PDF por el menú del sistema —en Windows 11, el catálogo de envío con Drive,
// WhatsApp, Correo y lo que el usuario tenga instalado— desde el propio renglón: compartirle su
// factura a un cliente es lo que más se hace desde esta pantalla (ver 007-facturacion.md).
const puedeCompartir = puedeCompartirArchivos()
const compartiendo = ref<number | null>(null)
const errorCompartir = ref<string | null>(null)
const avisoCompartir = ref<string | null>(null)

/**
 * Un PDF por factura mientras dure la página. No se bajan por adelantado los de toda la lista: son
 * archivos que casi nadie va a compartir.
 */
const pdfs = new Map<number, Promise<ArchivoCompartible>>()

/** Timbrada y cancelada tienen PDF; la pendiente todavía no es comprobante. */
function esCompartible(factura: Factura) {
  return factura.estado === 'timbrada' || factura.estado === 'cancelada'
}

function pdfDe(factura: Factura): Promise<ArchivoCompartible> {
  let pendiente = pdfs.get(factura.id)

  if (pendiente === undefined) {
    pendiente = facturas.archivoPdf(factura)
    // Una descarga que falló no se queda cacheada: el siguiente intento vuelve a pedirla.
    pendiente.catch(() => pdfs.delete(factura.id))
    pdfs.set(factura.id, pendiente)
  }

  return pendiente
}

/**
 * El puntero acercándose al botón —o el foco del teclado— basta para empezar a bajar el PDF: para
 * cuando llega el clic ya está en memoria y el menú del sistema abre de inmediato, que solo se abre
 * mientras el gesto del usuario sigue vivo (ver 029-pwa-mostrador.md).
 */
function precargarPdf(factura: Factura) {
  if (!puedeCompartir || !esCompartible(factura)) return

  void pdfDe(factura)
}

async function compartirPdf(factura: Factura) {
  avisoCompartir.value = null
  errorCompartir.value = null
  compartiendo.value = factura.id
  try {
    const archivo = await pdfDe(factura)

    if ((await facturas.compartirPdf(archivo)) === 'descargado') {
      avisoCompartir.value = 'El menú de compartir no se abrió: el PDF quedó en tus descargas.'
    }
  } catch (err) {
    errorCompartir.value = extractErrorMessage(err)
  } finally {
    compartiendo.value = null
  }
}
function abrirEliminar(factura: Factura) {
  facturaAEliminar.value = factura
  errorEliminar.value = null
}

function cerrarEliminar() {
  facturaAEliminar.value = null
  errorEliminar.value = null
}

async function confirmarEliminar() {
  if (!facturaAEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await facturas.remove(facturaAEliminar.value.id)
    facturaAEliminar.value = null
  } catch (err) {
    errorEliminar.value = extractErrorMessage(err)
  } finally {
    eliminando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">Facturas</h1>
        <Button as-child>
          <RouterLink :to="{ name: 'facturas-crear' }">
            <PlusIcon class="size-4" />
            Nueva factura
          </RouterLink>
        </Button>
      </div>

      <div class="flex flex-wrap gap-2">
        <Input
          v-model="facturas.search"
          placeholder="Buscar por folio, UUID o cliente..."
          class="max-w-sm"
          @update:model-value="onBuscar"
        />
        <select
          v-model="facturas.estado"
          class="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
          @change="onFiltrarEstado"
        >
          <option value="">Todos los estados</option>
          <option value="pendiente">Pendiente</option>
          <option value="timbrada">Timbrada</option>
          <option value="cancelada">Cancelada</option>
        </select>
      </div>

      <Alert v-if="facturas.error" variant="destructive">
        <AlertDescription>{{ facturas.error }}</AlertDescription>
      </Alert>

      <Alert v-if="errorCompartir" variant="destructive">
        <AlertDescription>{{ errorCompartir }}</AlertDescription>
      </Alert>

      <Alert v-if="avisoCompartir">
        <AlertDescription>{{ avisoCompartir }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Folio</TableHead>
                <TableHead>Cliente</TableHead>
                <TableHead>Total</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead>Fecha</TableHead>
                <TableHead class="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!facturas.loading && facturas.items.length === 0">
                <TableCell colspan="6" class="text-muted-foreground py-10 text-center">
                  No hay facturas registradas todavía.
                </TableCell>
              </TableRow>
              <TableRow v-for="factura in facturas.items" :key="factura.id">
                <TableCell
                  >{{ factura.facturapi_serie
                  }}{{ factura.facturapi_folio ?? factura.folio }}</TableCell
                >
                <TableCell>{{ factura.cliente_razon_social ?? '—' }}</TableCell>
                <TableCell>${{ factura.total.toFixed(2) }}</TableCell>
                <TableCell>
                  <Badge :variant="estadoVariant(factura.estado)">{{ factura.estado }}</Badge>
                </TableCell>
                <TableCell>{{ new Date(factura.created_at).toLocaleDateString() }}</TableCell>
                <TableCell class="flex justify-end gap-2 text-right">
                  <Button as-child variant="outline" size="icon-sm">
                    <RouterLink :to="{ name: 'facturas-detalle', params: { id: factura.id } }">
                      <EyeIcon class="size-4" />
                      <span class="sr-only">Ver</span>
                    </RouterLink>
                  </Button>
                  <Button
                    v-if="puedeCompartir && esCompartible(factura)"
                    variant="outline"
                    size="icon-sm"
                    :disabled="compartiendo === factura.id"
                    title="Compartir el PDF; el XML se manda por correo"
                    @mouseenter="precargarPdf(factura)"
                    @focus="precargarPdf(factura)"
                    @click="compartirPdf(factura)"
                  >
                    <ShareIcon class="size-4" />
                    <span class="sr-only">
                      {{ compartiendo === factura.id ? 'Preparando PDF' : 'Compartir PDF' }}
                    </span>
                  </Button>
                  <Button
                    v-if="factura.estado === 'pendiente'"
                    as-child
                    variant="outline"
                    size="icon-sm"
                  >
                    <RouterLink :to="{ name: 'facturas-editar', params: { id: factura.id } }">
                      <PencilIcon class="size-4" />
                      <span class="sr-only">Reintentar</span>
                    </RouterLink>
                  </Button>
                  <Button
                    v-if="factura.estado === 'pendiente' || factura.estado === 'borrador'"
                    variant="outline"
                    size="icon-sm"
                    @click="abrirEliminar(factura)"
                  >
                    <TrashIcon class="size-4" />
                    <span class="sr-only">Eliminar</span>
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div v-if="facturas.meta && facturas.meta.last_page > 1" class="flex justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="facturas.meta.current_page <= 1"
          @click="irAPagina(facturas.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ facturas.meta.current_page }} de {{ facturas.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="facturas.meta.current_page >= facturas.meta.last_page"
          @click="irAPagina(facturas.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="facturaAEliminar !== null" @update:open="(v) => !v && cerrarEliminar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar factura</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar la factura pendiente "{{ facturaAEliminar?.folio }}"?
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
    </div>
  </AppLayout>
</template>
