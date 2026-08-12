<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useOrdenesCompraStore, type OrdenCompraPayload } from '../stores/ordenesCompra'
import type { TipoDescuento } from '../stores/facturas'
import { calcularTotales } from '../lib/totalesDocumento'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../components/ui/dialog'
import AppLayout from '../layouts/AppLayout.vue'
import ProveedorSelect from '../components/ProveedorSelect.vue'
import DocumentoLineas, { type LineaEditable } from '../components/DocumentoLineas.vue'

const route = useRoute()
const router = useRouter()
const ordenes = useOrdenesCompraStore()

const ordenId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => ordenId.value !== null)

const form = reactive({
  proveedor_id: null as number | null,
  fecha_entrega_esperada: '',
  observaciones: '',
  descuento_global_tipo: null as TipoDescuento | null,
  descuento_global_valor: null as number | null,
})

const lineas = ref<LineaEditable[]>([])

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

// Mismo módulo de cálculo que usa el componente de líneas y que replica al backend, atado a él por
// el fixture compartido (ver 012-ordenes-compra.md, adición técnica 42).
const totales = computed(() =>
  calcularTotales(lineas.value, form.descuento_global_tipo, form.descuento_global_valor),
)

// Cambiar de proveedor invalida las líneas ya capturadas: los artículos de la orden tienen que
// pertenecer a catálogos del proveedor seleccionado (ver 012, supuesto #9). Se pide confirmación
// antes de vaciarlas.
const proveedorPendiente = ref<number | null>(null)
const mostrarCambioProveedor = ref(false)

const proveedorSeleccionado = computed({
  get: () => form.proveedor_id,
  set: (valor: number | null) => {
    if (valor === form.proveedor_id) return

    if (lineas.value.length > 0 && form.proveedor_id !== null) {
      proveedorPendiente.value = valor
      mostrarCambioProveedor.value = true
      return
    }

    form.proveedor_id = valor
  },
})

function confirmarCambioProveedor() {
  form.proveedor_id = proveedorPendiente.value
  lineas.value = []
  mostrarCambioProveedor.value = false
}

function cancelarCambioProveedor() {
  // Reasignar el mismo valor devuelve el Select a su estado anterior.
  proveedorPendiente.value = null
  mostrarCambioProveedor.value = false
}

onMounted(async () => {
  if (!ordenId.value) return

  cargando.value = true
  try {
    const orden = await ordenes.fetchOne(ordenId.value)
    form.proveedor_id = orden.proveedor_id
    form.fecha_entrega_esperada = orden.fecha_entrega_esperada ?? ''
    form.observaciones = orden.observaciones ?? ''
    form.descuento_global_tipo = orden.descuento_global_tipo
    form.descuento_global_valor = orden.descuento_global_valor
    lineas.value = orden.lineas.map((l) => ({
      articulo_id: l.articulo_id,
      cantidad: l.cantidad,
      descripcion: l.descripcion,
      modelo: l.modelo,
      precio_unitario: l.precio_unitario,
      descuento_tipo: l.descuento_tipo,
      descuento_valor: l.descuento_valor,
      tasa_iva: l.tasa_iva,
    }))
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

async function onSubmit() {
  guardando.value = true
  errorGeneral.value = null
  erroresPorCampo.value = {}

  const payload: OrdenCompraPayload = {
    proveedor_id: form.proveedor_id,
    fecha_entrega_esperada: form.fecha_entrega_esperada || null,
    observaciones: form.observaciones || null,
    descuento_global_tipo: form.descuento_global_tipo,
    descuento_global_valor: form.descuento_global_valor,
    lineas: lineas.value,
    total: totales.value.total,
  }

  try {
    const orden =
      esEdicion.value && ordenId.value
        ? await ordenes.update(ordenId.value, payload)
        : await ordenes.create(payload)

    await router.push({ name: 'ordenes-compra-detalle', params: { id: orden.id } })
  } catch (err) {
    erroresPorCampo.value = extractFieldErrors(err)
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-4xl space-y-4">
      <h1 class="font-heading text-foreground text-xl font-semibold">
        {{ esEdicion ? 'Editar orden de compra' : 'Nueva orden de compra' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Proveedor</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label>Proveedor</Label>
              <ProveedorSelect v-model="proveedorSeleccionado" />
              <p v-if="erroresPorCampo.proveedor_id" class="text-destructive text-sm">
                {{ erroresPorCampo.proveedor_id }}
              </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <div class="space-y-1.5">
                <Label for="fecha_entrega">Fecha esperada de entrega</Label>
                <Input id="fecha_entrega" v-model="form.fecha_entrega_esperada" type="date" />
                <p v-if="erroresPorCampo.fecha_entrega_esperada" class="text-destructive text-sm">
                  {{ erroresPorCampo.fecha_entrega_esperada }}
                </p>
              </div>
            </div>

            <div class="space-y-1.5">
              <Label for="observaciones">Observaciones para el proveedor</Label>
              <textarea
                id="observaciones"
                v-model="form.observaciones"
                rows="3"
                class="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                placeholder="Condiciones de entrega, referencias, instrucciones..."
              />
              <p v-if="erroresPorCampo.observaciones" class="text-destructive text-sm">
                {{ erroresPorCampo.observaciones }}
              </p>
            </div>
          </CardContent>
        </Card>

        <p v-if="!form.proveedor_id" class="text-muted-foreground text-sm">
          Selecciona un proveedor para poder agregar sus artículos.
        </p>

        <!-- Los precios se precargan con el costo del artículo, no con su precio de venta: es lo
             que le pagas al proveedor (ver 012-ordenes-compra.md, supuesto #7). -->
        <DocumentoLineas
          v-model:lineas="lineas"
          v-model:descuento-global-tipo="form.descuento_global_tipo"
          v-model:descuento-global-valor="form.descuento_global_valor"
          origen-precio="costo"
          :proveedor-id="form.proveedor_id"
          :error-lineas="erroresPorCampo.lineas ?? erroresPorCampo['lineas.0.articulo_id']"
        />

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'ordenes-compra' })">
            Cancelar
          </Button>
          <Button type="submit" :disabled="guardando || lineas.length === 0 || !form.proveedor_id">
            {{ guardando ? 'Guardando...' : 'Guardar orden' }}
          </Button>
        </div>
      </form>

      <Dialog :open="mostrarCambioProveedor" @update:open="(v) => !v && cancelarCambioProveedor()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cambiar de proveedor</DialogTitle>
            <DialogDescription>
              Las líneas capturadas son artículos del proveedor actual, así que se van a eliminar al
              cambiarlo. ¿Continuar?
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" @click="cancelarCambioProveedor">Cancelar</Button>
            <Button variant="destructive" @click="confirmarCambioProveedor">
              Cambiar y vaciar líneas
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
