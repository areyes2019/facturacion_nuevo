<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { useProveedoresStore, type Proveedor } from '../stores/proveedores'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent } from '../components/ui/card'
import { Input } from '../components/ui/input'
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

const proveedores = useProveedoresStore()

const proveedorAEliminar = ref<Proveedor | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

onMounted(() => proveedores.fetchList())

let buscarTimeout: ReturnType<typeof setTimeout>
function onBuscar() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => proveedores.fetchList(1), 300)
}

function irAPagina(pagina: number) {
  proveedores.fetchList(pagina)
}

function abrirEliminar(proveedor: Proveedor) {
  proveedorAEliminar.value = proveedor
  errorEliminar.value = null
}

function cerrarEliminar() {
  proveedorAEliminar.value = null
  errorEliminar.value = null
}

async function confirmarEliminar() {
  if (!proveedorAEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await proveedores.remove(proveedorAEliminar.value.id)
    proveedorAEliminar.value = null
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
      <div class="flex items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">Proveedores</h1>
        <Button as-child>
          <RouterLink :to="{ name: 'proveedores-crear' }">
            <PlusIcon class="size-4" />
            Nuevo proveedor
          </RouterLink>
        </Button>
      </div>

      <Input
        v-model="proveedores.search"
        placeholder="Buscar por nombre comercial o de contacto..."
        class="max-w-sm"
        @update:model-value="onBuscar"
      />

      <Alert v-if="proveedores.error" variant="destructive">
        <AlertDescription>{{ proveedores.error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nombre comercial</TableHead>
                <TableHead>Nombre de contacto</TableHead>
                <TableHead>Correo</TableHead>
                <TableHead>Teléfono</TableHead>
                <TableHead class="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!proveedores.loading && proveedores.items.length === 0">
                <TableCell colspan="5" class="text-muted-foreground py-10 text-center">
                  No hay proveedores registrados todavía.
                </TableCell>
              </TableRow>
              <TableRow v-for="proveedor in proveedores.items" :key="proveedor.id">
                <TableCell>{{ proveedor.nombre_comercial }}</TableCell>
                <TableCell>{{ proveedor.nombre_contacto ?? '—' }}</TableCell>
                <TableCell>{{ proveedor.correo ?? '—' }}</TableCell>
                <TableCell>{{ proveedor.telefono ?? '—' }}</TableCell>
                <TableCell class="flex justify-end gap-2 text-right">
                  <Button as-child variant="outline" size="icon-sm">
                    <RouterLink :to="{ name: 'proveedores-editar', params: { id: proveedor.id } }">
                      <PencilIcon class="size-4" />
                      <span class="sr-only">Editar</span>
                    </RouterLink>
                  </Button>
                  <Button variant="outline" size="icon-sm" @click="abrirEliminar(proveedor)">
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
        v-if="proveedores.meta && proveedores.meta.last_page > 1"
        class="flex justify-center gap-2"
      >
        <Button
          variant="outline"
          size="sm"
          :disabled="proveedores.meta.current_page <= 1"
          @click="irAPagina(proveedores.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ proveedores.meta.current_page }} de {{ proveedores.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="proveedores.meta.current_page >= proveedores.meta.last_page"
          @click="irAPagina(proveedores.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="proveedorAEliminar !== null" @update:open="(v) => !v && cerrarEliminar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar proveedor</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar a "{{ proveedorAEliminar?.nombre_comercial }}"? Podrás
              recuperarlo solo por soporte técnico.
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
