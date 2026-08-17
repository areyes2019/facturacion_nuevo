<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { PhotoIcon, PlusIcon } from '@heroicons/vue/24/outline'
import http from '../../lib/http'
import { mensajeDeFalla } from '../../lib/errors'
import { calcularTotales } from '../../lib/totalesDocumento'
import { descuentoDeCliente } from '../../lib/lineasMostrador'
import { useScrollInfinito } from '../../lib/scrollInfinito'
import { imagenUrl, type Articulo } from '../../stores/articulos'
import type { LineaEditable } from '../DocumentoLineas.vue'
import { Alert, AlertDescription } from '../ui/alert'
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
 * No es la tabla de escritorio adaptada ni un buscador que hay que abrir una vez por renglón: es el
 * catálogo en tarjetas, visible desde que abre la pantalla, donde **un toque suma una unidad y no
 * se sale**. Así se arma una venta de diez renglones sin entrar y volver diez veces; la cantidad
 * grande se corrige después, en el carrito, que es donde se ve todo junto.
 */

const props = withDefaults(
  defineProps<{
    /**
     * Habilita el "Artículo suelto": algo que se vendió y no está en el catálogo. Solo la venta al
     * público lo admite; factura y cotización exigen `articulo_id` en el backend.
     */
    permiteLineaLibre?: boolean
    /** Descuento permanente del cliente, que precarga cada línea nueva (ver 015). */
    descuentoPorcentaje?: number
    /** Texto del botón que cierra la captura: "Terminar cotización", "Terminar venta"... */
    etiquetaTerminar?: string
  }>(),
  { permiteLineaLibre: false, descuentoPorcentaje: 0, etiquetaTerminar: 'Terminar' },
)

const emit = defineEmits<{ terminar: [] }>()

const lineas = defineModel<LineaEditable[]>('lineas', { required: true })

const texto = ref('')
const articulos = ref<Articulo[]>([])
const pagina = ref(1)
const ultimaPagina = ref(1)
const cargando = ref(false)
const error = ref<string | null>(null)

const totales = computed(() => calcularTotales(lineas.value, null, null, true))
const unidades = computed(() => lineas.value.reduce((suma, linea) => suma + linea.cantidad, 0))

async function cargar(siguiente: number) {
  if (cargando.value) return

  cargando.value = true
  error.value = null

  try {
    const { data } = await http.get('/articulos', {
      params: { page: siguiente, search: texto.value.trim() || undefined },
    })

    articulos.value = siguiente === 1 ? data.data : [...articulos.value, ...data.data]
    pagina.value = data.meta.current_page
    ultimaPagina.value = data.meta.last_page
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    cargando.value = false
  }
}

void cargar(1)

const buscar = useDebounceFn(() => cargar(1), 300)

const centinela = useTemplateRef<HTMLElement>('centinela')

useScrollInfinito(centinela, () => {
  if (pagina.value < ultimaPagina.value) void cargar(pagina.value + 1)
})

function cantidadEnCarrito(articulo: Articulo): number {
  return lineas.value.find((linea) => linea.articulo_id === articulo.id)?.cantidad ?? 0
}

/**
 * Un artículo repetido suma unidades en la línea que ya existe, sin preguntar. En el mostrador
 * volver a tocar la misma tarjeta **es** la forma de pedir otro; el diálogo del escritorio, que
 * existe para atrapar un error de captura en una tabla larga, aquí solo estorbaría.
 */
function agregar(articulo: Articulo) {
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
    ...descuentoDeCliente(props.descuentoPorcentaje),
    tasa_iva: '16',
  })
}

// --- Artículo suelto (solo la venta al público) ---

const dialogoSuelto = ref(false)
const sueltoDescripcion = ref('')
const sueltoPrecio = ref<number | null>(null)

const sueltoValido = computed(
  () => sueltoDescripcion.value.trim() !== '' && (sueltoPrecio.value ?? 0) > 0,
)

function agregarSuelto() {
  if (!sueltoValido.value) return

  lineas.value.push({
    articulo_id: null,
    cantidad: 1,
    descripcion: sueltoDescripcion.value.trim(),
    modelo: '',
    precio_unitario: Number(sueltoPrecio.value),
    ...descuentoDeCliente(props.descuentoPorcentaje),
    tasa_iva: '16',
  })

  dialogoSuelto.value = false
  sueltoDescripcion.value = ''
  sueltoPrecio.value = null
}
</script>

<template>
  <div class="space-y-4">
    <Input
      v-model="texto"
      placeholder="Buscar artículo..."
      class="h-12 text-base"
      @update:model-value="buscar()"
    />

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

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <p v-if="!cargando && articulos.length === 0" class="text-muted-foreground py-8 text-center">
      No hay artículos que coincidan.
    </p>

    <ul class="space-y-2 pb-2">
      <li v-for="articulo in articulos" :key="articulo.id">
        <button
          type="button"
          class="border-border bg-background hover:bg-accent focus-visible:ring-ring flex w-full items-center gap-3 rounded-lg border p-3 text-left focus-visible:ring-2 focus-visible:outline-none"
          @click="agregar(articulo)"
        >
          <!-- Sin imagen va el recuadro con ícono: un hueco descuadraría la lista y haría dudar
               de si algo falló (ver 020-imagenes-articulos.md). -->
          <img
            v-if="imagenUrl(articulo)"
            :src="imagenUrl(articulo) ?? undefined"
            :alt="articulo.nombre"
            loading="lazy"
            class="bg-muted size-16 shrink-0 rounded object-cover"
          />
          <div v-else class="bg-muted flex size-16 shrink-0 items-center justify-center rounded">
            <PhotoIcon class="text-muted-foreground size-7" />
          </div>

          <div class="min-w-0 flex-1">
            <p class="text-foreground truncate font-medium">{{ articulo.nombre }}</p>
            <p class="text-muted-foreground truncate text-sm">{{ articulo.modelo }}</p>
            <p class="text-foreground font-semibold">
              ${{ articulo.precio_unitario_sin_iva.toFixed(2) }}
            </p>
          </div>

          <span
            v-if="cantidadEnCarrito(articulo) > 0"
            class="bg-primary text-primary-foreground flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
          >
            {{ cantidadEnCarrito(articulo) }}
          </span>
        </button>
      </li>
    </ul>

    <div v-if="cargando" class="text-muted-foreground py-4 text-center">Cargando...</div>
    <div v-else-if="pagina < ultimaPagina" ref="centinela" class="h-px"></div>

    <!-- El total a la vista mientras se agrega es lo que el cliente pregunta enfrente. -->
    <div class="bg-background border-border sticky bottom-0 space-y-2 border-t pt-3 pb-3">
      <div class="text-muted-foreground flex items-baseline justify-between">
        <span>{{ unidades }} {{ unidades === 1 ? 'artículo' : 'artículos' }}</span>
        <span class="text-foreground text-2xl font-semibold">
          ${{ totales.total.toFixed(2) }}
        </span>
      </div>
      <Button
        type="button"
        class="h-14 w-full text-base"
        :disabled="lineas.length === 0"
        @click="emit('terminar')"
      >
        {{ etiquetaTerminar }}
      </Button>
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
