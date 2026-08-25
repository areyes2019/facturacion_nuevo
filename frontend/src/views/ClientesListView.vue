<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { useClientesStore, type Cliente } from '../stores/clientes'
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

const clientes = useClientesStore()

const clienteAEliminar = ref<Cliente | null>(null)
const eliminando = ref(false)

onMounted(() => clientes.fetchList())

let buscarTimeout: ReturnType<typeof setTimeout>
function onBuscar() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => clientes.fetchList(1), 300)
}

function irAPagina(pagina: number) {
  clientes.fetchList(pagina)
}

async function confirmarEliminar() {
  if (!clienteAEliminar.value) return

  eliminando.value = true
  try {
    await clientes.remove(clienteAEliminar.value.id)
    clienteAEliminar.value = null
  } finally {
    eliminando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="space-y-4">
      <div class="flex items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">Clientes</h1>
        <Button as-child>
          <RouterLink :to="{ name: 'clientes-crear' }">
            <PlusIcon class="size-4" />
            Nuevo cliente
          </RouterLink>
        </Button>
      </div>

      <Input
        v-model="clientes.search"
        placeholder="Buscar por razón social, nombre comercial o RFC..."
        class="max-w-sm"
        @update:model-value="onBuscar"
      />

      <Alert v-if="clientes.error" variant="destructive">
        <AlertDescription>{{ clientes.error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>RFC</TableHead>
                <TableHead>Razón social</TableHead>
                <TableHead>Nombre comercial</TableHead>
                <TableHead>Régimen fiscal</TableHead>
                <TableHead class="text-right">Descuento</TableHead>
                <TableHead>Distribuidor</TableHead>
                <TableHead class="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!clientes.loading && clientes.items.length === 0">
                <TableCell colspan="7" class="text-muted-foreground py-10 text-center">
                  No hay clientes registrados todavía.
                </TableCell>
              </TableRow>
              <TableRow v-for="cliente in clientes.items" :key="cliente.id">
                <TableCell class="font-mono">{{ cliente.rfc }}</TableCell>
                <TableCell>{{ cliente.razon_social }}</TableCell>
                <TableCell>{{ cliente.nombre_comercial ?? '—' }}</TableCell>
                <TableCell>{{ cliente.regimen_fiscal }}</TableCell>
                <TableCell class="text-right">
                  {{ cliente.descuento_permanente > 0 ? `${cliente.descuento_permanente}%` : '—' }}
                </TableCell>
                <TableCell>
                  <Badge v-if="cliente.es_distribuidor" variant="secondary">Distribuidor</Badge>
                  <span v-else class="text-muted-foreground">—</span>
                </TableCell>
                <TableCell class="flex justify-end gap-2 text-right">
                  <Button as-child variant="outline" size="icon-sm">
                    <RouterLink :to="{ name: 'clientes-editar', params: { id: cliente.id } }">
                      <PencilIcon class="size-4" />
                      <span class="sr-only">Editar</span>
                    </RouterLink>
                  </Button>
                  <Button variant="outline" size="icon-sm" @click="clienteAEliminar = cliente">
                    <TrashIcon class="size-4" />
                    <span class="sr-only">Eliminar</span>
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div v-if="clientes.meta && clientes.meta.last_page > 1" class="flex justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="clientes.meta.current_page <= 1"
          @click="irAPagina(clientes.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ clientes.meta.current_page }} de {{ clientes.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="clientes.meta.current_page >= clientes.meta.last_page"
          @click="irAPagina(clientes.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog
        :open="clienteAEliminar !== null"
        @update:open="(v) => !v && (clienteAEliminar = null)"
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar cliente</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar a "{{ clienteAEliminar?.razon_social }}"? Podrás
              recuperarlo solo por soporte técnico.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" :disabled="eliminando" @click="clienteAEliminar = null">
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
