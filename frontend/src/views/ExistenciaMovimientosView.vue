<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'
import { useInventarioStore, type MovimientoInventario } from '../stores/inventario'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent } from '../components/ui/card'
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

const route = useRoute()
const inventario = useInventarioStore()

const articuloId = Number(route.params.id)
const movimientos = ref<MovimientoInventario[]>([])
const meta = ref<{ current_page: number; last_page: number; total: number } | null>(null)
const cargando = ref(false)
const error = ref<string | null>(null)

async function cargar(page = 1) {
  cargando.value = true
  error.value = null
  try {
    const resultado = await inventario.fetchMovimientos(articuloId, page)
    movimientos.value = resultado.items
    meta.value = resultado.meta
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
}

onMounted(() => cargar())

function fecha(iso: string): string {
  return new Date(iso).toLocaleString('es-MX', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/** Ruta del documento que originó el movimiento, para poder abrirlo desde el historial. */
function rutaDocumento(documento: NonNullable<MovimientoInventario['documento']>) {
  const nombres = {
    orden_compra: 'ordenes-compra-detalle',
    factura: 'facturas-detalle',
    cotizacion: 'cotizaciones-detalle',
  } as const

  return { name: nombres[documento.tipo], params: { id: documento.id } }
}
</script>

<template>
  <AppLayout>
    <div class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">
          Movimientos de inventario
        </h1>
        <Button as-child variant="outline">
          <RouterLink :to="{ name: 'existencias' }">
            <ArrowLeftIcon class="size-4" />
            Volver a Existencias
          </RouterLink>
        </Button>
      </div>

      <p class="text-muted-foreground text-sm">
        Historial completo del artículo, de lo más reciente a lo más antiguo. Es solo de consulta:
        un error se corrige con un ajuste nuevo, no borrando el movimiento viejo.
      </p>

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Fecha</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead>Motivo</TableHead>
                <TableHead class="text-right">Cantidad</TableHead>
                <TableHead class="text-right">Existencia</TableHead>
                <TableHead class="text-right">Faltante</TableHead>
                <TableHead>Documento</TableHead>
                <TableHead>Nota</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!cargando && movimientos.length === 0">
                <TableCell colspan="8" class="text-muted-foreground py-10 text-center">
                  Este artículo todavía no tiene movimientos.
                </TableCell>
              </TableRow>
              <TableRow v-for="movimiento in movimientos" :key="movimiento.id">
                <TableCell class="whitespace-nowrap">{{ fecha(movimiento.created_at) }}</TableCell>
                <TableCell>
                  <Badge :variant="movimiento.tipo === 'salida' ? 'destructive' : 'secondary'">
                    {{ movimiento.tipo_texto }}
                  </Badge>
                </TableCell>
                <TableCell>{{ movimiento.motivo_texto }}</TableCell>
                <TableCell class="text-right tabular-nums">{{ movimiento.cantidad }}</TableCell>
                <TableCell class="text-right tabular-nums">
                  {{ movimiento.existencia_resultante }}
                </TableCell>
                <TableCell class="text-right tabular-nums">
                  {{ movimiento.faltante_resultante }}
                </TableCell>
                <TableCell>
                  <RouterLink
                    v-if="movimiento.documento"
                    :to="rutaDocumento(movimiento.documento)"
                    class="text-primary underline-offset-4 hover:underline"
                  >
                    {{ movimiento.documento.folio }}
                  </RouterLink>
                  <span v-else class="text-muted-foreground">—</span>
                </TableCell>
                <TableCell truncate :title="movimiento.nota ?? undefined">
                  {{ movimiento.nota ?? '—' }}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div v-if="meta && meta.last_page > 1" class="flex justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="meta.current_page <= 1"
          @click="cargar(meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ meta.current_page }} de {{ meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="meta.current_page >= meta.last_page"
          @click="cargar(meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>
    </div>
  </AppLayout>
</template>
