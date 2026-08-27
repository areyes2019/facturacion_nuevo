<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useArticulosStore, type Articulo } from '../stores/articulos'
import { Button } from './ui/button'
import { Card, CardContent, CardHeader, CardTitle } from './ui/card'
import { Input } from './ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from './ui/table'
import ArticuloDetalleDialog from './ArticuloDetalleDialog.vue'

/**
 * Lista de precios de solo consulta en la página principal (ver 042). Reutiliza el mismo store que
 * `ArticulosListView.vue` (mismo `search`, `items`, `meta`), pero sin tocar `filtros` de columna ni
 * `sort`, que quedan reservados a la vista completa de `/articulos`.
 */
const articulos = useArticulosStore()

const articuloDetalle = ref<Articulo | null>(null)

function pesos(valor: number): string {
  return valor.toFixed(2)
}

let recargarTimeout: ReturnType<typeof setTimeout>
function recargarConRebote() {
  clearTimeout(recargarTimeout)
  recargarTimeout = setTimeout(() => articulos.fetchList(1), 300)
}

function irAPagina(pagina: number) {
  articulos.fetchList(pagina)
}

onMounted(() => {
  articulos.fetchList()
})
</script>

<template>
  <Card>
    <CardHeader class="flex flex-row items-center justify-between gap-2">
      <CardTitle>Lista de precios</CardTitle>
      <Button as-child variant="outline" size="sm">
        <RouterLink :to="{ name: 'articulos' }">Ver todos</RouterLink>
      </Button>
    </CardHeader>
    <CardContent class="space-y-4">
      <Input
        v-model="articulos.search"
        placeholder="Buscar por nombre, modelo o proveedor..."
        class="max-w-sm"
        @update:model-value="recargarConRebote"
      />

      <Table class="table-fixed">
        <TableHeader>
          <TableRow>
            <TableHead>Nombre</TableHead>
            <TableHead class="w-32">Modelo</TableHead>
            <TableHead class="w-24">Precio</TableHead>
            <TableHead class="w-24">P Dist</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-if="!articulos.loading && articulos.items.length === 0">
            <TableCell colspan="4" class="text-muted-foreground py-10 text-center">
              {{
                articulos.search
                  ? 'Ningún artículo coincide con la búsqueda.'
                  : 'No hay artículos registrados todavía.'
              }}
            </TableCell>
          </TableRow>
          <TableRow v-for="articulo in articulos.items" :key="articulo.id">
            <TableCell class="whitespace-normal">
              <button
                type="button"
                class="hover:text-primary block w-full text-left font-medium underline-offset-4 hover:underline"
                @click="articuloDetalle = articulo"
              >
                {{ articulo.nombre }}
              </button>
            </TableCell>
            <TableCell class="truncate" :title="articulo.modelo">{{ articulo.modelo }}</TableCell>
            <TableCell class="tabular-nums"
              >${{ pesos(articulo.precio_unitario_con_iva) }}</TableCell
            >
            <TableCell class="tabular-nums"
              >${{ pesos(articulo.precio_distribuidor_con_iva) }}</TableCell
            >
          </TableRow>
        </TableBody>
      </Table>

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
    </CardContent>
  </Card>

  <ArticuloDetalleDialog
    :articulo="articuloDetalle"
    :mostrar-editar="false"
    @update:open="(v) => !v && (articuloDetalle = null)"
  />
</template>
