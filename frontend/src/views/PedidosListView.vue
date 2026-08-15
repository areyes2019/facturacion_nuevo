<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  PlusIcon,
  EyeIcon,
  PencilIcon,
  TrashIcon,
  ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'
import { usePedidosStore, type Pedido, type EstadoPedido } from '../stores/pedidos'
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

const pedidos = usePedidosStore()

const pedidoAEliminar = ref<Pedido | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

onMounted(() => {
  pedidos.fetchList()
})

let buscarTimeout: ReturnType<typeof setTimeout>
function onFiltrarTexto() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => pedidos.fetchList(1), 300)
}

function onFiltrar() {
  pedidos.fetchList(1)
}

function irAPagina(pagina: number) {
  pedidos.fetchList(pagina)
}

function estadoVariant(estado: EstadoPedido) {
  return {
    pendiente: 'secondary',
    anticipo: 'warning',
    pagado: 'success',
    entregado: 'default',
  }[estado] as 'secondary' | 'warning' | 'success' | 'default'
}

function estadoTexto(estado: EstadoPedido) {
  return {
    pendiente: 'Pendiente de pago',
    anticipo: 'Con anticipo',
    pagado: 'Pagado',
    entregado: 'Entregado',
  }[estado]
}

function abrirEliminar(pedido: Pedido) {
  pedidoAEliminar.value = pedido
  errorEliminar.value = null
}

function cerrarEliminar() {
  pedidoAEliminar.value = null
  errorEliminar.value = null
}

async function confirmarEliminar() {
  if (!pedidoAEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await pedidos.remove(pedidoAEliminar.value.id)
    pedidoAEliminar.value = null
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
        <h1 class="font-heading text-foreground text-xl font-semibold">Pedidos de mostrador</h1>
        <Button as-child>
          <RouterLink :to="{ name: 'pedidos-crear' }">
            <PlusIcon class="size-4" />
            Nuevo pedido
          </RouterLink>
        </Button>
      </div>

      <div class="flex flex-wrap gap-2">
        <Input
          v-model="pedidos.filtroCliente"
          placeholder="Cliente..."
          class="max-w-40"
          @update:model-value="onFiltrarTexto"
        />
        <Input
          v-model="pedidos.filtroTelefono"
          placeholder="Teléfono..."
          class="max-w-36"
          @update:model-value="onFiltrarTexto"
        />
        <Input
          v-model="pedidos.filtroFolio"
          placeholder="No. ticket..."
          class="max-w-28"
          @update:model-value="onFiltrarTexto"
        />
        <select
          v-model="pedidos.filtroEstado"
          class="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
          @change="onFiltrar"
        >
          <option value="">Todos los estados</option>
          <option value="pendiente">Pendiente de pago</option>
          <option value="anticipo">Con anticipo</option>
          <option value="pagado">Pagado</option>
          <option value="entregado">Entregado</option>
        </select>
        <Input
          v-model="pedidos.filtroFechaDesde"
          type="date"
          class="w-40"
          @update:model-value="onFiltrar"
        />
        <span class="text-muted-foreground self-center text-sm">a</span>
        <Input
          v-model="pedidos.filtroFechaHasta"
          type="date"
          class="w-40"
          @update:model-value="onFiltrar"
        />
      </div>

      <Alert v-if="pedidos.error" variant="destructive">
        <AlertDescription>{{ pedidos.error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <div class="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>No. ticket</TableHead>
                  <TableHead>Cliente</TableHead>
                  <TableHead>Teléfono</TableHead>
                  <TableHead class="text-right">Total</TableHead>
                  <TableHead class="text-right">Saldo</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead>Fecha</TableHead>
                  <TableHead class="text-right">Acciones</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-if="!pedidos.loading && pedidos.items.length === 0">
                  <TableCell colspan="8" class="text-muted-foreground py-10 text-center">
                    No hay pedidos de mostrador todavía.
                  </TableCell>
                </TableRow>
                <TableRow v-for="pedido in pedidos.items" :key="pedido.id">
                  <TableCell class="font-mono">{{ pedido.numero_ticket }}</TableCell>
                  <TableCell>{{ pedido.cliente_nombre }}</TableCell>
                  <TableCell>{{ pedido.cliente_telefono }}</TableCell>
                  <TableCell class="text-right">${{ pedido.total.toFixed(2) }}</TableCell>
                  <TableCell class="text-right">${{ pedido.saldo_pendiente.toFixed(2) }}</TableCell>
                  <TableCell class="space-y-1">
                    <Badge :variant="estadoVariant(pedido.estado)">
                      {{ estadoTexto(pedido.estado) }}
                    </Badge>
                    <!-- Un cliente intentó facturar y no pudo. Ya se fue y no va a insistir: esta
                         señal es la única forma de enterarse (ver 027). -->
                    <Badge v-if="pedido.autofactura_error" variant="destructive" class="gap-1">
                      <ExclamationTriangleIcon class="size-3" />
                      Falló la autofactura
                    </Badge>
                  </TableCell>
                  <TableCell>{{ new Date(pedido.created_at).toLocaleDateString() }}</TableCell>
                  <TableCell class="flex justify-end gap-2 text-right">
                    <Button as-child variant="outline" size="icon-sm">
                      <RouterLink :to="{ name: 'pedidos-detalle', params: { id: pedido.id } }">
                        <EyeIcon class="size-4" />
                        <span class="sr-only">Ver</span>
                      </RouterLink>
                    </Button>
                    <Button v-if="pedido.es_editable" as-child variant="outline" size="icon-sm">
                      <RouterLink :to="{ name: 'pedidos-editar', params: { id: pedido.id } }">
                        <PencilIcon class="size-4" />
                        <span class="sr-only">Editar</span>
                      </RouterLink>
                    </Button>
                    <Button
                      v-if="pedido.puede_eliminarse"
                      variant="outline"
                      size="icon-sm"
                      @click="abrirEliminar(pedido)"
                    >
                      <TrashIcon class="size-4" />
                      <span class="sr-only">Eliminar</span>
                    </Button>
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>

      <div v-if="pedidos.meta && pedidos.meta.last_page > 1" class="flex justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="pedidos.meta.current_page <= 1"
          @click="irAPagina(pedidos.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ pedidos.meta.current_page }} de {{ pedidos.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="pedidos.meta.current_page >= pedidos.meta.last_page"
          @click="irAPagina(pedidos.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="pedidoAEliminar !== null" @update:open="(v) => !v && cerrarEliminar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar pedido</DialogTitle>
            <DialogDescription>
              Se eliminará el pedido {{ pedidoAEliminar?.numero_ticket }} con todas sus líneas y se
              devolverán las existencias al inventario. Es definitivo: no hay papelera de la que
              recuperarlo.
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
