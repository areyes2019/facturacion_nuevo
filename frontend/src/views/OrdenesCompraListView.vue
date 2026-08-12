<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { PlusIcon, EyeIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import {
  useOrdenesCompraStore,
  type OrdenCompra,
  type EstadoOrdenCompra,
} from '../stores/ordenesCompra'
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

const ordenes = useOrdenesCompraStore()

const ordenAEliminar = ref<OrdenCompra | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

function formatearFecha(fecha: Date): string {
  return fecha.toISOString().slice(0, 10)
}

function aplicarRangoEsteMes() {
  const hoy = new Date()
  const primerDia = new Date(hoy.getFullYear(), hoy.getMonth(), 1)
  const ultimoDia = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0)
  ordenes.filtroFechaDesde = formatearFecha(primerDia)
  ordenes.filtroFechaHasta = formatearFecha(ultimoDia)
}

function aplicarRangoHoy() {
  const hoy = formatearFecha(new Date())
  ordenes.filtroFechaDesde = hoy
  ordenes.filtroFechaHasta = hoy
}

function aplicarRangoEstaSemana() {
  const hoy = new Date()
  const diaSemana = hoy.getDay() === 0 ? 7 : hoy.getDay()
  const lunes = new Date(hoy)
  lunes.setDate(hoy.getDate() - (diaSemana - 1))
  const domingo = new Date(lunes)
  domingo.setDate(lunes.getDate() + 6)
  ordenes.filtroFechaDesde = formatearFecha(lunes)
  ordenes.filtroFechaHasta = formatearFecha(domingo)
}

onMounted(() => {
  aplicarRangoEsteMes()
  ordenes.fetchList()
})

let buscarTimeout: ReturnType<typeof setTimeout>
function onFiltrarTexto() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => ordenes.fetchList(1), 300)
}

function onFiltrar() {
  ordenes.fetchList(1)
}

function onAtajoFecha(atajo: 'hoy' | 'semana' | 'mes') {
  if (atajo === 'hoy') aplicarRangoHoy()
  else if (atajo === 'semana') aplicarRangoEstaSemana()
  else aplicarRangoEsteMes()
  onFiltrar()
}

function irAPagina(pagina: number) {
  ordenes.fetchList(pagina)
}

function estadoVariant(estado: EstadoOrdenCompra) {
  return {
    borrador: 'secondary',
    enviada: 'warning',
    pagada: 'success',
    recibida: 'default',
  }[estado] as 'secondary' | 'warning' | 'success' | 'default'
}

function abrirEliminar(orden: OrdenCompra) {
  ordenAEliminar.value = orden
  errorEliminar.value = null
}

function cerrarEliminar() {
  ordenAEliminar.value = null
  errorEliminar.value = null
}

async function confirmarEliminar() {
  if (!ordenAEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await ordenes.remove(ordenAEliminar.value.id)
    ordenAEliminar.value = null
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
        <h1 class="font-heading text-foreground text-xl font-semibold">Órdenes de compra</h1>
        <Button as-child>
          <RouterLink :to="{ name: 'ordenes-compra-crear' }">
            <PlusIcon class="size-4" />
            Nueva orden
          </RouterLink>
        </Button>
      </div>

      <div class="flex flex-wrap gap-2">
        <Input
          v-model="ordenes.filtroProveedor"
          placeholder="Proveedor..."
          class="max-w-40"
          @update:model-value="onFiltrarTexto"
        />
        <Input
          v-model="ordenes.filtroRfc"
          placeholder="RFC..."
          class="max-w-32"
          @update:model-value="onFiltrarTexto"
        />
        <Input
          v-model="ordenes.filtroFolio"
          placeholder="Folio..."
          class="max-w-24"
          @update:model-value="onFiltrarTexto"
        />
        <select
          v-model="ordenes.filtroEstado"
          class="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
          @change="onFiltrar"
        >
          <option value="">Todos los estados</option>
          <option value="borrador">Borrador</option>
          <option value="enviada">Enviada</option>
          <option value="pagada">Pagada</option>
          <option value="recibida">Recibida</option>
        </select>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Button variant="outline" size="sm" @click="onAtajoFecha('hoy')">Hoy</Button>
        <Button variant="outline" size="sm" @click="onAtajoFecha('semana')">Esta semana</Button>
        <Button variant="outline" size="sm" @click="onAtajoFecha('mes')">Este mes</Button>
        <Input
          v-model="ordenes.filtroFechaDesde"
          type="date"
          class="w-40"
          @update:model-value="onFiltrar"
        />
        <span class="text-muted-foreground text-sm">a</span>
        <Input
          v-model="ordenes.filtroFechaHasta"
          type="date"
          class="w-40"
          @update:model-value="onFiltrar"
        />
      </div>

      <Alert v-if="ordenes.error" variant="destructive">
        <AlertDescription>{{ ordenes.error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Folio</TableHead>
                <TableHead>Proveedor</TableHead>
                <TableHead>Total</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead>Fecha</TableHead>
                <TableHead class="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!ordenes.loading && ordenes.items.length === 0">
                <TableCell colspan="6" class="text-muted-foreground py-10 text-center">
                  No hay órdenes de compra registradas todavía.
                </TableCell>
              </TableRow>
              <TableRow v-for="orden in ordenes.items" :key="orden.id">
                <TableCell>{{ orden.folio_formateado }}</TableCell>
                <TableCell>{{ orden.proveedor_nombre_comercial ?? '—' }}</TableCell>
                <TableCell>${{ orden.total.toFixed(2) }}</TableCell>
                <TableCell>
                  <Badge :variant="estadoVariant(orden.estado)">{{ orden.estado }}</Badge>
                </TableCell>
                <TableCell>{{ new Date(orden.created_at).toLocaleDateString() }}</TableCell>
                <TableCell class="flex justify-end gap-2 text-right">
                  <Button as-child variant="outline" size="icon-sm">
                    <RouterLink :to="{ name: 'ordenes-compra-detalle', params: { id: orden.id } }">
                      <EyeIcon class="size-4" />
                      <span class="sr-only">Ver</span>
                    </RouterLink>
                  </Button>
                  <Button
                    v-if="orden.estado === 'borrador' || orden.estado === 'enviada'"
                    as-child
                    variant="outline"
                    size="icon-sm"
                  >
                    <RouterLink :to="{ name: 'ordenes-compra-editar', params: { id: orden.id } }">
                      <PencilIcon class="size-4" />
                      <span class="sr-only">Editar</span>
                    </RouterLink>
                  </Button>
                  <Button
                    v-if="orden.estado === 'borrador'"
                    variant="outline"
                    size="icon-sm"
                    @click="abrirEliminar(orden)"
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

      <div v-if="ordenes.meta && ordenes.meta.last_page > 1" class="flex justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="ordenes.meta.current_page <= 1"
          @click="irAPagina(ordenes.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ ordenes.meta.current_page }} de {{ ordenes.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="ordenes.meta.current_page >= ordenes.meta.last_page"
          @click="irAPagina(ordenes.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="ordenAEliminar !== null" @update:open="(v) => !v && cerrarEliminar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar orden de compra</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar la orden "{{ ordenAEliminar?.folio_formateado }}"?
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
