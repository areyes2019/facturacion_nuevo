<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { MinusIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { calcularTotales, importeNetoLinea } from '../../lib/totalesDocumento'
import ArticuloBuscador, { type ArticuloResultado } from '../ArticuloBuscador.vue'
import type { LineaEditable } from '../DocumentoLineas.vue'
import { Button } from '../ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../ui/dialog'
import { Input } from '../ui/input'
import { Label } from '../ui/label'

/**
 * El paso de artículos, el mismo en los tres caminos del mostrador (ver 029-pwa-mostrador.md).
 *
 * No es la tabla de escritorio adaptada: es una lista de tarjetas, un renglón por artículo, con su
 * cantidad en botones de "−" y "+". Sin columnas y sin descuentos por renglón —el descuento fino se
 * aplica en la computadora—, porque en un celular la tabla hay que arrastrarla de lado para ver de
 * qué columna es cada número.
 *
 * Los totales salen de `lib/totalesDocumento.ts`, el mismo módulo que usan los formularios de
 * escritorio: un centavo de diferencia entre las dos caras del sistema sería imposible de
 * explicarle a un cliente.
 */
const props = withDefaults(
  defineProps<{
    /**
     * Habilita el "Artículo suelto": algo que se vendió y no está en el catálogo. Solo la venta al
     * público lo admite; factura y cotización exigen `articulo_id` en el backend.
     */
    permiteLineaLibre?: boolean
    /**
     * Descuento permanente del cliente, que precarga cada línea (ver
     * 015-descuento-permanente-cliente.md). Va aquí y no en la mano del usuario: es del cliente, no
     * de la captura, y sin él la cotización del celular no cuadraría con la de la computadora.
     */
    descuentoPorcentaje?: number
  }>(),
  { permiteLineaLibre: false, descuentoPorcentaje: 0 },
)

const lineas = defineModel<LineaEditable[]>('lineas', { required: true })

const totales = computed(() => calcularTotales(lineas.value, null, null))

const dialogoSuelto = ref(false)
const sueltoDescripcion = ref('')
const sueltoPrecio = ref<number | null>(null)

const sueltoValido = computed(
  () => sueltoDescripcion.value.trim() !== '' && (sueltoPrecio.value ?? 0) > 0,
)

function descuentoDeLineaNueva() {
  return props.descuentoPorcentaje > 0
    ? { descuento_tipo: 'porcentaje' as const, descuento_valor: props.descuentoPorcentaje }
    : { descuento_tipo: null, descuento_valor: null }
}

/**
 * Cambiar de cliente reemplaza el descuento de todas las líneas ya capturadas, misma regla que el
 * formulario de escritorio: lo capturado antes se pensó para otro cliente (ver 015, supuesto 12).
 */
watch(
  () => props.descuentoPorcentaje,
  () => {
    lineas.value = lineas.value.map((linea) => ({ ...linea, ...descuentoDeLineaNueva() }))
  },
)

/**
 * Un artículo repetido suma unidades en la línea que ya existe, sin preguntar. En el mostrador
 * volver a buscar el mismo artículo **es** la forma de pedir otro; el diálogo del escritorio, que
 * existe para atrapar un error de captura en una tabla larga, aquí solo estorbaría.
 */
function onArticuloSeleccionado(articulo: ArticuloResultado) {
  const existente = lineas.value.find((linea) => linea.articulo_id === articulo.id)

  if (existente) {
    existente.cantidad += 1
    return
  }

  lineas.value.push({
    articulo_id: articulo.id,
    cantidad: 1,
    descripcion: articulo.nombre,
    modelo: articulo.modelo,
    precio_unitario: articulo.precio_unitario_sin_iva,
    ...descuentoDeLineaNueva(),
    tasa_iva: '16',
  })
}

function agregarSuelto() {
  if (!sueltoValido.value) return

  lineas.value.push({
    articulo_id: null,
    cantidad: 1,
    descripcion: sueltoDescripcion.value.trim(),
    modelo: '',
    precio_unitario: Number(sueltoPrecio.value),
    ...descuentoDeLineaNueva(),
    tasa_iva: '16',
  })

  dialogoSuelto.value = false
  sueltoDescripcion.value = ''
  sueltoPrecio.value = null
}

function cambiarCantidad(indice: number, delta: number) {
  const linea = lineas.value[indice]

  if (!linea) return

  // Bajar de uno es quitar la línea, que es lo que el usuario quiso decir apretando "−" en 1.
  if (linea.cantidad + delta < 1) {
    quitar(indice)
    return
  }

  linea.cantidad += delta
}

function quitar(indice: number) {
  lineas.value.splice(indice, 1)
}
</script>

<template>
  <div class="space-y-4">
    <ArticuloBuscador placeholder="Buscar artículo..." @seleccionar="onArticuloSeleccionado" />

    <Button
      v-if="permiteLineaLibre"
      type="button"
      variant="outline"
      class="h-12 w-full text-base"
      @click="dialogoSuelto = true"
    >
      <PlusIcon class="size-5" />
      Artículo suelto
    </Button>

    <p v-if="lineas.length === 0" class="text-muted-foreground py-8 text-center">
      Todavía no has agregado nada.
    </p>

    <ul class="space-y-2">
      <li
        v-for="(linea, i) in lineas"
        :key="i"
        class="border-border bg-background rounded-lg border p-3"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="text-foreground font-medium">{{ linea.descripcion }}</p>
            <p class="text-muted-foreground text-sm">
              ${{ linea.precio_unitario.toFixed(2) }} c/u
              <template v-if="linea.descuento_tipo === 'porcentaje' && linea.descuento_valor">
                · −{{ linea.descuento_valor }}%
              </template>
            </p>
          </div>
          <Button type="button" variant="ghost" size="icon-lg" @click="quitar(i)">
            <TrashIcon class="size-5" />
            <span class="sr-only">Quitar {{ linea.descripcion }}</span>
          </Button>
        </div>

        <div class="mt-2 flex items-center justify-between gap-2">
          <div class="flex items-center gap-1">
            <Button type="button" variant="outline" size="icon-lg" @click="cambiarCantidad(i, -1)">
              <MinusIcon class="size-5" />
              <span class="sr-only">Quitar una unidad</span>
            </Button>
            <span class="w-10 text-center text-lg font-semibold">{{ linea.cantidad }}</span>
            <Button type="button" variant="outline" size="icon-lg" @click="cambiarCantidad(i, 1)">
              <PlusIcon class="size-5" />
              <span class="sr-only">Agregar una unidad</span>
            </Button>
          </div>
          <span class="text-lg font-semibold">${{ importeNetoLinea(linea).toFixed(2) }}</span>
        </div>
      </li>
    </ul>

    <div
      v-if="lineas.length > 0"
      class="border-border flex items-baseline justify-between border-t pt-3"
    >
      <span class="text-muted-foreground">Total</span>
      <span class="text-2xl font-semibold">${{ totales.total.toFixed(2) }}</span>
    </div>

    <Dialog v-model:open="dialogoSuelto">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Artículo suelto</DialogTitle>
          <DialogDescription>
            Algo que vendiste y no está en el catálogo. El precio se captura con IVA aparte, igual
            que el del catálogo.
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-4">
          <div class="space-y-1.5">
            <Label for="suelto-descripcion">Descripción</Label>
            <Input id="suelto-descripcion" v-model="sueltoDescripcion" class="h-12" />
          </div>
          <div class="space-y-1.5">
            <Label for="suelto-precio">Precio unitario</Label>
            <Input
              id="suelto-precio"
              :model-value="sueltoPrecio ?? undefined"
              type="number"
              inputmode="decimal"
              min="0.01"
              step="0.01"
              class="h-12"
              @update:model-value="(v) => (sueltoPrecio = v === '' ? null : Number(v))"
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" @click="dialogoSuelto = false">Cancelar</Button>
          <Button :disabled="!sueltoValido" @click="agregarSuelto">Agregar</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
