<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { PlusIcon, PencilIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { useCatalogosStore, type Catalogo } from '../stores/catalogos'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
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

const catalogos = useCatalogosStore()

const catalogoAEliminar = ref<Catalogo | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

/**
 * Cerrojo del borrado de un catálogo con artículos (ver 021-mantenimiento-articulos-catalogos.md):
 * un clic accidental se lleva cientos de artículos, y aunque el borrado sea lógico, recuperarlos
 * exige soporte técnico. Escribir el nombre es imposible de hacer por inercia.
 */
const nombreConfirmacion = ref('')

const catalogoTieneArticulos = computed(() => (catalogoAEliminar.value?.articulos_count ?? 0) > 0)

const puedeEliminar = computed(
  () =>
    !catalogoTieneArticulos.value ||
    nombreConfirmacion.value.trim() === catalogoAEliminar.value?.nombre,
)

onMounted(() => catalogos.fetchList())

let buscarTimeout: ReturnType<typeof setTimeout>
function onBuscar() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => catalogos.fetchList(1), 300)
}

function irAPagina(pagina: number) {
  catalogos.fetchList(pagina)
}

function abrirEliminar(catalogo: Catalogo) {
  catalogoAEliminar.value = catalogo
  errorEliminar.value = null
  nombreConfirmacion.value = ''
}

function cerrarEliminar() {
  catalogoAEliminar.value = null
  errorEliminar.value = null
  nombreConfirmacion.value = ''
}

async function confirmarEliminar() {
  if (!catalogoAEliminar.value || !puedeEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await catalogos.remove(catalogoAEliminar.value.id)
    catalogoAEliminar.value = null
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
        <h1 class="font-heading text-foreground text-xl font-semibold">Catálogos</h1>
        <Button as-child>
          <RouterLink :to="{ name: 'catalogos-crear' }">
            <PlusIcon class="size-4" />
            Nuevo catálogo
          </RouterLink>
        </Button>
      </div>

      <Input
        v-model="catalogos.search"
        placeholder="Buscar por nombre de catálogo o proveedor..."
        class="max-w-sm"
        @update:model-value="onBuscar"
      />

      <Alert v-if="catalogos.error" variant="destructive">
        <AlertDescription>{{ catalogos.error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nombre</TableHead>
                <TableHead>Proveedor</TableHead>
                <TableHead>Descuento</TableHead>
                <TableHead class="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!catalogos.loading && catalogos.items.length === 0">
                <TableCell colspan="4" class="text-muted-foreground py-10 text-center">
                  No hay catálogos registrados todavía.
                </TableCell>
              </TableRow>
              <TableRow v-for="catalogo in catalogos.items" :key="catalogo.id">
                <TableCell>{{ catalogo.nombre }}</TableCell>
                <TableCell>{{ catalogo.proveedor_nombre_comercial ?? '—' }}</TableCell>
                <TableCell>{{ catalogo.descuento }}%</TableCell>
                <TableCell class="flex justify-end gap-2 text-right">
                  <Button as-child variant="outline" size="icon-sm">
                    <RouterLink :to="{ name: 'catalogos-editar', params: { id: catalogo.id } }">
                      <PencilIcon class="size-4" />
                      <span class="sr-only">Editar</span>
                    </RouterLink>
                  </Button>
                  <Button variant="outline" size="icon-sm" @click="abrirEliminar(catalogo)">
                    <TrashIcon class="size-4" />
                    <span class="sr-only">Eliminar</span>
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div v-if="catalogos.meta && catalogos.meta.last_page > 1" class="flex justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="catalogos.meta.current_page <= 1"
          @click="irAPagina(catalogos.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ catalogos.meta.current_page }} de {{ catalogos.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="catalogos.meta.current_page >= catalogos.meta.last_page"
          @click="irAPagina(catalogos.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="catalogoAEliminar !== null" @update:open="(v) => !v && cerrarEliminar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar catálogo</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar "{{ catalogoAEliminar?.nombre }}"? Podrás recuperarlo
              solo por soporte técnico.
            </DialogDescription>
          </DialogHeader>

          <div v-if="catalogoTieneArticulos" class="min-w-0 space-y-3">
            <p class="text-destructive text-sm font-medium">
              Se eliminarán también {{ catalogoAEliminar?.articulos_count }} artículo{{
                catalogoAEliminar?.articulos_count === 1 ? '' : 's'
              }}
              de este catálogo.
            </p>
            <div class="space-y-1.5">
              <Label for="confirmar_nombre">
                Escribe "{{ catalogoAEliminar?.nombre }}" para confirmar
              </Label>
              <Input id="confirmar_nombre" v-model="nombreConfirmacion" autocomplete="off" />
            </div>
          </div>

          <Alert v-if="errorEliminar" variant="destructive">
            <AlertDescription>{{ errorEliminar }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="eliminando" @click="cerrarEliminar">
              Cancelar
            </Button>
            <Button
              variant="destructive"
              :disabled="eliminando || !puedeEliminar"
              @click="confirmarEliminar"
            >
              {{ eliminando ? 'Eliminando...' : 'Eliminar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
