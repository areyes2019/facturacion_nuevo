<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { PlusIcon, EyeIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { useFacturasStore, type Factura, type EstadoFactura } from '../stores/facturas'
import { extractErrorMessage } from '../lib/errors'
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
