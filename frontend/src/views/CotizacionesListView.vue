<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { PlusIcon, EyeIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import {
  useCotizacionesStore,
  type Cotizacion,
  type EstadoCotizacion,
} from '../stores/cotizaciones'
import { extractErrorMessage } from '../lib/errors'
import { caducaPronto, textoCaducidad } from '../lib/caducidadCotizacion'
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

const cotizaciones = useCotizacionesStore()

const cotizacionAEliminar = ref<Cotizacion | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

function formatearFecha(fecha: Date): string {
  return fecha.toISOString().slice(0, 10)
}

function aplicarRangoEsteMes() {
  const hoy = new Date()
  const primerDia = new Date(hoy.getFullYear(), hoy.getMonth(), 1)
  const ultimoDia = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0)
  cotizaciones.filtroFechaDesde = formatearFecha(primerDia)
  cotizaciones.filtroFechaHasta = formatearFecha(ultimoDia)
}

function aplicarRangoHoy() {
  const hoy = formatearFecha(new Date())
  cotizaciones.filtroFechaDesde = hoy
  cotizaciones.filtroFechaHasta = hoy
}

function aplicarRangoEstaSemana() {
  const hoy = new Date()
  const diaSemana = hoy.getDay() === 0 ? 7 : hoy.getDay()
  const lunes = new Date(hoy)
  lunes.setDate(hoy.getDate() - (diaSemana - 1))
  const domingo = new Date(lunes)
  domingo.setDate(lunes.getDate() + 6)
  cotizaciones.filtroFechaDesde = formatearFecha(lunes)
  cotizaciones.filtroFechaHasta = formatearFecha(domingo)
}

onMounted(() => {
  aplicarRangoEsteMes()
  cotizaciones.fetchList()
})

let buscarTimeout: ReturnType<typeof setTimeout>
function onFiltrarTexto() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => cotizaciones.fetchList(1), 300)
}

function onFiltrar() {
  cotizaciones.fetchList(1)
}

function onAtajoFecha(atajo: 'hoy' | 'semana' | 'mes') {
  if (atajo === 'hoy') aplicarRangoHoy()
  else if (atajo === 'semana') aplicarRangoEstaSemana()
  else aplicarRangoEsteMes()
  onFiltrar()
}

function irAPagina(pagina: number) {
  cotizaciones.fetchList(pagina)
}

function estadoVariant(estado: EstadoCotizacion) {
  return {
    borrador: 'secondary',
    enviada: 'warning',
    pagada: 'success',
    producto_entregado: 'default',
  }[estado] as 'secondary' | 'warning' | 'success' | 'default'
}

function abrirEliminar(cotizacion: Cotizacion) {
  cotizacionAEliminar.value = cotizacion
  errorEliminar.value = null
}

function cerrarEliminar() {
  cotizacionAEliminar.value = null
  errorEliminar.value = null
}

async function confirmarEliminar() {
  if (!cotizacionAEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await cotizaciones.remove(cotizacionAEliminar.value.id)
    cotizacionAEliminar.value = null
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
        <h1 class="font-heading text-foreground text-xl font-semibold">Cotizaciones</h1>
        <Button as-child>
          <RouterLink :to="{ name: 'cotizaciones-crear' }">
            <PlusIcon class="size-4" />
            Nueva cotización
          </RouterLink>
        </Button>
      </div>

      <div class="flex flex-wrap gap-2">
        <Input
          v-model="cotizaciones.filtroCliente"
          placeholder="Cliente..."
          class="max-w-40"
          @update:model-value="onFiltrarTexto"
        />
        <Input
          v-model="cotizaciones.filtroRfc"
          placeholder="RFC..."
          class="max-w-32"
          @update:model-value="onFiltrarTexto"
        />
        <Input
          v-model="cotizaciones.filtroFolio"
          placeholder="Folio..."
          class="max-w-24"
          @update:model-value="onFiltrarTexto"
        />
        <select
          v-model="cotizaciones.filtroEstado"
          class="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
          @change="onFiltrar"
        >
          <option value="">Todos los estados</option>
          <option value="borrador">Borrador</option>
          <option value="enviada">Enviada</option>
          <option value="pagada">Pagada</option>
          <option value="producto_entregado">Producto entregado</option>
        </select>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Button variant="outline" size="sm" @click="onAtajoFecha('hoy')">Hoy</Button>
        <Button variant="outline" size="sm" @click="onAtajoFecha('semana')">Esta semana</Button>
        <Button variant="outline" size="sm" @click="onAtajoFecha('mes')">Este mes</Button>
        <Input
          v-model="cotizaciones.filtroFechaDesde"
          type="date"
          class="w-40"
          @update:model-value="onFiltrar"
        />
        <span class="text-muted-foreground text-sm">a</span>
        <Input
          v-model="cotizaciones.filtroFechaHasta"
          type="date"
          class="w-40"
          @update:model-value="onFiltrar"
        />
      </div>

      <Alert v-if="cotizaciones.error" variant="destructive">
        <AlertDescription>{{ cotizaciones.error }}</AlertDescription>
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
              <TableRow v-if="!cotizaciones.loading && cotizaciones.items.length === 0">
                <TableCell colspan="6" class="text-muted-foreground py-10 text-center">
                  No hay cotizaciones registradas todavía.
                </TableCell>
              </TableRow>
              <TableRow v-for="cotizacion in cotizaciones.items" :key="cotizacion.id">
                <TableCell>{{ cotizacion.folio }}</TableCell>
                <TableCell>{{ cotizacion.cliente_razon_social ?? '—' }}</TableCell>
                <TableCell>${{ cotizacion.total.toFixed(2) }}</TableCell>
                <TableCell class="space-y-1">
                  <Badge :variant="estadoVariant(cotizacion.estado)">{{ cotizacion.estado }}</Badge>
                  <!-- Avisa del borrado automático por inactividad antes de que ocurra: es
                       físico y no hay papelera (ver 008-cotizaciones.md). -->
                  <Badge v-if="caducaPronto(cotizacion.caduca_el)" variant="destructive">
                    {{ textoCaducidad(cotizacion.caduca_el) }}
                  </Badge>
                </TableCell>
                <TableCell>{{ new Date(cotizacion.created_at).toLocaleDateString() }}</TableCell>
                <TableCell class="flex justify-end gap-2 text-right">
                  <Button as-child variant="outline" size="icon-sm">
                    <RouterLink
                      :to="{ name: 'cotizaciones-detalle', params: { id: cotizacion.id } }"
                    >
                      <EyeIcon class="size-4" />
                      <span class="sr-only">Ver</span>
                    </RouterLink>
                  </Button>
                  <Button
                    v-if="cotizacion.estado === 'borrador' || cotizacion.estado === 'enviada'"
                    as-child
                    variant="outline"
                    size="icon-sm"
                  >
                    <RouterLink
                      :to="{ name: 'cotizaciones-editar', params: { id: cotizacion.id } }"
                    >
                      <PencilIcon class="size-4" />
                      <span class="sr-only">Editar</span>
                    </RouterLink>
                  </Button>
                  <Button
                    v-if="cotizacion.puede_eliminarse"
                    variant="outline"
                    size="icon-sm"
                    @click="abrirEliminar(cotizacion)"
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

      <div
        v-if="cotizaciones.meta && cotizaciones.meta.last_page > 1"
        class="flex justify-center gap-2"
      >
        <Button
          variant="outline"
          size="sm"
          :disabled="cotizaciones.meta.current_page <= 1"
          @click="irAPagina(cotizaciones.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ cotizaciones.meta.current_page }} de {{ cotizaciones.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="cotizaciones.meta.current_page >= cotizaciones.meta.last_page"
          @click="irAPagina(cotizaciones.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="cotizacionAEliminar !== null" @update:open="(v) => !v && cerrarEliminar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar cotización</DialogTitle>
            <DialogDescription>
              Se eliminará la cotización {{ cotizacionAEliminar?.folio }} con todas sus líneas. Es
              definitivo: no hay papelera de la que recuperarla.
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
