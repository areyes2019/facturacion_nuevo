<script setup lang="ts">
import { computed, ref } from 'vue'
import { TrashIcon } from '@heroicons/vue/24/outline'
import type { TasaIva, TipoDescuento } from '../stores/facturas'
import { calcularTotales, importeNetoLinea } from '../lib/totalesDocumento'
import { Button } from './ui/button'
import { Card, CardContent, CardHeader, CardTitle } from './ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from './ui/dialog'
import { Input } from './ui/input'
import { Label } from './ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from './ui/table'
import TasaIvaSelect from './TasaIvaSelect.vue'
import ArticuloBuscador, { type ArticuloResultado } from './ArticuloBuscador.vue'

export interface LineaEditable {
  /**
   * Null es la **línea libre**: algo que se vendió y no está en el catálogo. Solo la venta de
   * mostrador la habilita (ver 027-venta-mostrador-ticket.md); en factura, cotización y orden de
   * compra el buscador siempre pone un id y el backend la exige.
   */
  articulo_id: number | null
  cantidad: number
  descripcion: string
  modelo: string
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
  /**
   * Precios del artículo en el momento en que se agregó (o se refrescó) la línea, cacheados solo
   * para poder reemplazar `precio_unitario` sin volver a consultar el artículo cuando cambia el
   * cliente (ver 033-precio-distribuidor.md). No viajan al backend.
   */
  precio_directo_sin_iva?: number
  precio_distribuidor_sin_iva?: number
}

/**
 * Tabla de captura de líneas compartida por Factura (007), Cotización (008) y Orden de compra
 * (012). Antes existía una copia por vista; la tercera fue la que justificó extraerla (ver
 * 012-ordenes-compra.md, adición técnica 40).
 *
 * Lo único que difiere entre documentos se parametriza: de dónde se precarga el precio del
 * artículo, qué artículos ofrece el buscador y las etiquetas de la columna de precio.
 */
const props = withDefaults(
  defineProps<{
    /** `venta` precarga el precio de venta del artículo; `costo`, lo que le pagas al proveedor. */
    origenPrecio?: 'venta' | 'costo'
    /** Limita el buscador a los artículos de catálogos de este proveedor. */
    proveedorId?: number | null
    /** Bloquea toda la captura (documento ya no editable). */
    soloLectura?: boolean
    errorLineas?: string | null
    /**
     * Descuento porcentual con el que nace cada línea nueva (descuento permanente del cliente, ver
     * 015-descuento-permanente-cliente.md). En 0 —factura y orden de compra, que no lo pasan— la
     * línea nace sin descuento, exactamente como antes.
     */
    descuentoPorDefectoPorcentaje?: number
    /**
     * El cliente elegido es distribuidor (ver 033-precio-distribuidor.md): cada línea nueva nace
     * con el precio distribuidor del artículo en vez del directo. En `false` —orden de compra y
     * venta de mostrador, que no la pasan— el componente se comporta exactamente como hoy.
     */
    precioDistribuidor?: boolean
    /**
     * Habilita el botón de línea libre: una fila sin artículo del catálogo, con descripción y
     * precio escritos a mano. Solo la venta de mostrador la usa (ver 027).
     */
    permiteLineaLibre?: boolean
    /**
     * Cierra el total en peso cerrado (ver 030-total-al-peso-cerrado.md). Lo piden factura,
     * cotizacion y pedido; la orden de compra no, porque paga lo que cobra el proveedor.
     */
    redondearAlPeso?: boolean
  }>(),
  {
    origenPrecio: 'venta',
    proveedorId: null,
    soloLectura: false,
    errorLineas: null,
    descuentoPorDefectoPorcentaje: 0,
    precioDistribuidor: false,
    permiteLineaLibre: false,
    redondearAlPeso: false,
  },
)

const lineas = defineModel<LineaEditable[]>('lineas', { required: true })
const descuentoGlobalTipo = defineModel<TipoDescuento | null>('descuentoGlobalTipo', {
  default: null,
})
const descuentoGlobalValor = defineModel<number | null>('descuentoGlobalValor', { default: null })

const etiquetaPrecio = computed(() =>
  props.origenPrecio === 'costo' ? 'Costo unit.' : 'P. unitario',
)

const totales = computed(() =>
  calcularTotales(
    lineas.value,
    descuentoGlobalTipo.value,
    descuentoGlobalValor.value,
    props.redondearAlPeso,
  ),
)

/**
 * Un artículo vive en una sola línea por documento: repetirlo es siempre un error de captura (ver
 * 008-cotizaciones.md, Frontend). Cuando se detecta, en vez de agregar la línea se abre el diálogo
 * con la línea ya capturada para sumarle unidades.
 */
const indiceDuplicado = ref<number | null>(null)
const cantidadASumar = ref(1)

const lineaDuplicada = computed(() =>
  indiceDuplicado.value === null ? null : (lineas.value[indiceDuplicado.value] ?? null),
)

const cantidadASumarValida = computed(
  () => Number.isInteger(cantidadASumar.value) && cantidadASumar.value >= 1,
)

function onArticuloSeleccionado(articulo: ArticuloResultado) {
  const indice = lineas.value.findIndex((l) => l.articulo_id === articulo.id)
  if (indice !== -1) {
    indiceDuplicado.value = indice
    cantidadASumar.value = 1
    return
  }

  lineas.value.push({
    articulo_id: articulo.id,
    cantidad: 1,
    descripcion: articulo.nombre,
    modelo: articulo.modelo,
    // El precio precargado es editable; capturar otro no modifica el catálogo (ver 012,
    // supuestos #8 y #10). Si el cliente es distribuidor, nace con el precio distribuidor en vez
    // del directo (ver 033-precio-distribuidor.md).
    precio_unitario:
      props.origenPrecio === 'costo'
        ? articulo.costo_con_descuento
        : props.precioDistribuidor
          ? articulo.precio_distribuidor_sin_iva
          : articulo.precio_unitario_sin_iva,
    // El descuento precargado queda en su columna, editable como cualquier otro; reemplazarlo en
    // las líneas ya capturadas al cambiar de cliente es responsabilidad de la vista.
    descuento_tipo: props.descuentoPorDefectoPorcentaje > 0 ? 'porcentaje' : null,
    descuento_valor:
      props.descuentoPorDefectoPorcentaje > 0 ? props.descuentoPorDefectoPorcentaje : null,
    tasa_iva: '16',
    // Cacheados solo para poder reemplazar el precio si cambia el cliente, sin volver a consultar
    // el artículo (ver 033-precio-distribuidor.md). No aplica a orden de compra (origenPrecio
    // 'costo'), que no usa ninguno de los dos precios de venta.
    precio_directo_sin_iva:
      props.origenPrecio === 'costo' ? undefined : articulo.precio_unitario_sin_iva,
    precio_distribuidor_sin_iva:
      props.origenPrecio === 'costo' ? undefined : articulo.precio_distribuidor_sin_iva,
  })
}

/**
 * Solo toca la cantidad: precio, descripción, modelo, descuento y tasa de IVA de la línea quedan
 * como los dejó el usuario (incluido el descuento permanente del cliente, ver 015).
 */
function sumarADuplicada() {
  const linea = lineaDuplicada.value
  if (!linea || !cantidadASumarValida.value) return

  linea.cantidad += cantidadASumar.value
  indiceDuplicado.value = null
}

/**
 * Agrega una fila vacía sin artículo. No pasa por la detección de duplicados: dos servicios
 * distintos escritos a mano son dos líneas legítimas, no un error de captura.
 */
function agregarLineaLibre() {
  lineas.value.push({
    articulo_id: null,
    cantidad: 1,
    descripcion: '',
    modelo: '',
    precio_unitario: 0,
    descuento_tipo: null,
    descuento_valor: null,
    tasa_iva: '16',
  })
}

function quitarLinea(indice: number) {
  lineas.value.splice(indice, 1)
}
</script>

<template>
  <div class="space-y-6">
    <Card>
      <CardHeader>
        <CardTitle class="text-base">Artículos</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div v-if="!soloLectura" class="flex flex-col gap-2 sm:flex-row sm:items-start">
          <div class="min-w-0 flex-1">
            <ArticuloBuscador
              :proveedor-id="proveedorId"
              :origen-precio="origenPrecio"
              @seleccionar="onArticuloSeleccionado"
            />
          </div>
          <Button
            v-if="permiteLineaLibre"
            type="button"
            variant="outline"
            class="shrink-0"
            @click="agregarLineaLibre"
          >
            Agregar línea libre
          </Button>
        </div>
        <p v-if="errorLineas" class="text-destructive text-sm">{{ errorLineas }}</p>

        <div class="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead class="w-20">Cantidad</TableHead>
                <TableHead>Descripción</TableHead>
                <TableHead>Modelo</TableHead>
                <TableHead class="w-28">{{ etiquetaPrecio }}</TableHead>
                <TableHead class="w-32">Descuento</TableHead>
                <TableHead class="w-24">IVA</TableHead>
                <TableHead class="w-24 text-right">Total</TableHead>
                <TableHead class="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="lineas.length === 0">
                <TableCell colspan="8" class="text-muted-foreground py-6 text-center">
                  Agrega al menos un artículo.
                </TableCell>
              </TableRow>
              <TableRow v-for="(linea, i) in lineas" :key="i">
                <TableCell>
                  <Input
                    v-model.number="linea.cantidad"
                    type="number"
                    min="1"
                    step="1"
                    :disabled="soloLectura"
                  />
                </TableCell>
                <TableCell>
                  <Input v-model="linea.descripcion" :disabled="soloLectura" />
                </TableCell>
                <TableCell>
                  <Input v-model="linea.modelo" :disabled="soloLectura" />
                </TableCell>
                <TableCell>
                  <Input
                    v-model.number="linea.precio_unitario"
                    type="number"
                    min="0.01"
                    step="0.01"
                    :disabled="soloLectura"
                  />
                </TableCell>
                <TableCell class="flex gap-1">
                  <select
                    v-model="linea.descuento_tipo"
                    class="border-input h-9 rounded-md border bg-transparent px-1 text-xs"
                    :disabled="soloLectura"
                  >
                    <option :value="null">—</option>
                    <option value="porcentaje">%</option>
                    <option value="monto">$</option>
                  </select>
                  <Input
                    :model-value="linea.descuento_valor ?? undefined"
                    type="number"
                    min="0"
                    step="0.01"
                    class="w-16"
                    :disabled="soloLectura"
                    @update:model-value="
                      (v) => (linea.descuento_valor = v === '' ? null : Number(v))
                    "
                  />
                </TableCell>
                <TableCell>
                  <TasaIvaSelect v-model="linea.tasa_iva" :disabled="soloLectura" />
                </TableCell>
                <TableCell class="text-right">${{ importeNetoLinea(linea).toFixed(2) }}</TableCell>
                <TableCell>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon-sm"
                    :disabled="soloLectura"
                    @click="quitarLinea(i)"
                  >
                    <TrashIcon class="size-4" />
                    <span class="sr-only">Quitar</span>
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <CardTitle class="text-base">Descuento global y totales</CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="flex gap-2">
          <div class="space-y-1.5">
            <Label>Tipo de descuento global</Label>
            <select
              v-model="descuentoGlobalTipo"
              class="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
              :disabled="soloLectura"
            >
              <option :value="null">Sin descuento</option>
              <option value="porcentaje">Porcentaje</option>
              <option value="monto">Monto fijo</option>
            </select>
          </div>
          <div class="space-y-1.5">
            <Label>Valor</Label>
            <Input
              :model-value="descuentoGlobalValor ?? undefined"
              type="number"
              min="0"
              step="0.01"
              :disabled="soloLectura"
              @update:model-value="(v) => (descuentoGlobalValor = v === '' ? null : Number(v))"
            />
          </div>
        </div>

        <div class="ml-auto max-w-xs space-y-1 text-sm">
          <div class="flex justify-between">
            <span>Subtotal</span><span>${{ totales.subtotal.toFixed(2) }}</span>
          </div>
          <div class="flex justify-between">
            <span>Descuento global</span><span>${{ totales.descuento_global.toFixed(2) }}</span>
          </div>
          <div class="flex justify-between">
            <span>IVA 16%</span><span>${{ totales.total_iva_16.toFixed(2) }}</span>
          </div>
          <div v-if="totales.ajuste_al_peso > 0" class="flex justify-between">
            <span>Ajuste al peso</span><span>${{ totales.ajuste_al_peso.toFixed(2) }}</span>
          </div>
          <div class="text-foreground flex justify-between border-t pt-1 text-base font-semibold">
            <span>Total</span><span>${{ totales.total.toFixed(2) }}</span>
          </div>
        </div>
      </CardContent>
    </Card>

    <Dialog :open="indiceDuplicado !== null" @update:open="(v) => !v && (indiceDuplicado = null)">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Artículo duplicado</DialogTitle>
          <DialogDescription>
            <template v-if="lineaDuplicada">
              «{{ lineaDuplicada.descripcion }}»
              <template v-if="lineaDuplicada.modelo">
                (modelo {{ lineaDuplicada.modelo }})</template
              >
              ya está capturado en la línea {{ (indiceDuplicado ?? 0) + 1 }}, con una cantidad de
              {{ lineaDuplicada.cantidad }}. Puedes sumarle unidades en vez de agregar otra línea.
            </template>
          </DialogDescription>
        </DialogHeader>
        <div class="min-w-0 space-y-1.5">
          <Label for="cantidad-a-sumar">Cantidad a sumar</Label>
          <Input
            id="cantidad-a-sumar"
            v-model.number="cantidadASumar"
            type="number"
            min="1"
            step="1"
          />
        </div>
        <DialogFooter>
          <Button variant="outline" @click="indiceDuplicado = null">Cancelar</Button>
          <Button :disabled="!cantidadASumarValida" @click="sumarADuplicada">
            Sumar a la línea existente
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
