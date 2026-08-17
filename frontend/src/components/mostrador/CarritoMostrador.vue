<script setup lang="ts">
import { computed } from 'vue'
import { MinusIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { calcularTotales, importeNetoLinea } from '../../lib/totalesDocumento'
import type { LineaEditable } from '../DocumentoLineas.vue'
import { Button } from '../ui/button'

/**
 * El carrito del mostrador (ver 029-pwa-mostrador.md): lo capturado, con sus cantidades, en su
 * propia pantalla.
 *
 * Es la lista que antes vivía debajo del buscador, ahora separada de él: buscar y revisar son dos
 * trabajos distintos y en un celular no caben cómodos en la misma pantalla. Sin columnas y sin
 * descuentos por renglón —el descuento fino se aplica en la computadora—, porque la tabla del
 * escritorio hay que arrastrarla de lado para ver de qué columna es cada número.
 *
 * Los totales salen de `lib/totalesDocumento.ts`, el mismo módulo que usan los formularios de
 * escritorio: un centavo de diferencia entre las dos caras del sistema sería imposible de
 * explicarle a un cliente.
 */

const lineas = defineModel<LineaEditable[]>('lineas', { required: true })

const totales = computed(() => calcularTotales(lineas.value, null, null, true))

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
    <p v-if="lineas.length === 0" class="text-muted-foreground py-8 text-center">
      El carrito está vacío.
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
  </div>
</template>
