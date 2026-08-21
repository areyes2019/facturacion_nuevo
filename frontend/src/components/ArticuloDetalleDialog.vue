<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { PencilIcon, ShareIcon, PhotoIcon } from '@heroicons/vue/24/outline'
import { imagenUrl, type Articulo } from '../stores/articulos'
import http from '../lib/http'
import { comoJpeg } from '../lib/imagenCompartible'
import { Button } from './ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from './ui/dialog'

/**
 * Ficha visual de un artículo (ver 020-imagenes-articulos.md): foto grande a la izquierda, datos a
 * la derecha y los botones de compartir abajo.
 *
 * Es lo que se le enseña o se le manda a un cliente, así que muestra los precios de venta con IVA
 * (directo y distribuidor, ver 033-precio-distribuidor.md) y **nunca** el precio del proveedor, el
 * costo ni la utilidad.
 */
const props = defineProps<{ articulo: Articulo | null }>()
const emit = defineEmits<{ 'update:open': [boolean] }>()

const compartiendo = ref(false)
const aviso = ref<string | null>(null)

const url = computed(() => (props.articulo ? imagenUrl(props.articulo) : null))

const precioConIva = computed(() =>
  props.articulo ? `$${props.articulo.precio_unitario_con_iva.toFixed(2)}` : '',
)
const precioDistribuidorConIva = computed(() =>
  props.articulo ? `$${props.articulo.precio_distribuidor_con_iva.toFixed(2)}` : '',
)

// Rotular "con IVA" un artículo que no causa impuesto sería falso: ahí el precio a secas es el que
// paga el cliente (ver 024-precios-sin-centavos.md).
const etiquetaPrecio = computed(() =>
  props.articulo?.objeto_imp === '02' ? 'Precio con IVA' : 'Precio',
)
const etiquetaPrecioDistribuidor = computed(() =>
  props.articulo?.objeto_imp === '02' ? 'Precio distribuidor con IVA' : 'Precio distribuidor',
)

watch(
  () => props.articulo,
  () => {
    aviso.value = null
  },
)

/**
 * En celular abre el menú del propio aparato con la foto y el texto; en escritorio copia el texto.
 *
 * La decisión se toma preguntándole al navegador si puede compartir archivos, no por el ancho de la
 * pantalla: en escritorio muchos navegadores no tienen ese menú, y un botón que a veces hace algo y
 * a veces no es peor que dos comportamientos claros.
 *
 * Cada uno de los dos botones (precio directo / precio distribuidor, ver 033-precio-distribuidor.md)
 * llama a esta misma función con el texto que le corresponde: el texto compartido siempre lleva un
 * solo precio, nunca los dos juntos, para que quien comparte elija de entrada a cuál cliente le está
 * hablando.
 */
async function compartir(precio: string) {
  if (!props.articulo) return

  compartiendo.value = true
  aviso.value = null

  const texto = `${props.articulo.nombre} — Modelo ${props.articulo.modelo} — ${precio}`

  try {
    if (url.value && typeof navigator.canShare === 'function') {
      const { data } = await http.get(`/articulos/${props.articulo.id}/imagen`, {
        responseType: 'blob',
      })

      const archivo = new File([await comoJpeg(data)], `${props.articulo.modelo}.jpg`, {
        type: 'image/jpeg',
      })

      if (navigator.canShare({ files: [archivo] })) {
        await navigator.share({ text: texto, files: [archivo] })

        return
      }
    }

    await navigator.clipboard.writeText(texto)
    aviso.value = 'Datos copiados al portapapeles.'
  } catch (err) {
    // Cancelar el menú de compartir lanza AbortError y no es un fallo que reportar.
    if (err instanceof DOMException && err.name === 'AbortError') return

    aviso.value = 'No se pudo compartir.'
  } finally {
    compartiendo.value = false
  }
}
</script>

<template>
  <Dialog :open="articulo !== null" @update:open="(v) => emit('update:open', v)">
    <DialogContent class="sm:max-w-3xl">
      <DialogHeader>
        <DialogTitle class="min-w-0 break-words">{{ articulo?.nombre }}</DialogTitle>
        <DialogDescription>Ficha del producto</DialogDescription>
      </DialogHeader>

      <div v-if="articulo" class="grid min-w-0 gap-6 sm:grid-cols-2">
        <div
          class="bg-muted flex aspect-square min-w-0 items-center justify-center overflow-hidden rounded-md"
        >
          <img
            v-if="url"
            :src="url"
            :alt="articulo.nombre"
            class="h-full w-full object-contain"
            loading="lazy"
          />
          <div v-else class="text-muted-foreground flex flex-col items-center gap-2 text-sm">
            <PhotoIcon class="size-10" />
            Sin imagen
          </div>
        </div>

        <div class="flex min-w-0 flex-col gap-4">
          <div class="min-w-0 space-y-3">
            <div>
              <p class="text-muted-foreground text-xs uppercase">Producto</p>
              <p class="font-medium break-words">{{ articulo.nombre }}</p>
            </div>
            <div>
              <p class="text-muted-foreground text-xs uppercase">Modelo</p>
              <p class="font-medium break-words">{{ articulo.modelo }}</p>
            </div>
            <div>
              <p class="text-muted-foreground text-xs uppercase">{{ etiquetaPrecio }}</p>
              <p class="text-2xl font-semibold tabular-nums">{{ precioConIva }}</p>
            </div>
            <div>
              <p class="text-muted-foreground text-xs uppercase">
                {{ etiquetaPrecioDistribuidor }}
              </p>
              <p class="text-2xl font-semibold tabular-nums">{{ precioDistribuidorConIva }}</p>
            </div>
          </div>

          <p v-if="aviso" class="text-muted-foreground text-sm">{{ aviso }}</p>

          <div class="mt-auto flex flex-wrap justify-end gap-2">
            <Button as-child variant="outline">
              <RouterLink :to="{ name: 'articulos-editar', params: { id: articulo.id } }">
                <PencilIcon class="size-4" />
                Editar
              </RouterLink>
            </Button>
            <Button
              variant="outline"
              :disabled="compartiendo"
              @click="compartir(precioDistribuidorConIva)"
            >
              <ShareIcon class="size-4" />
              {{ compartiendo ? 'Compartiendo...' : 'Compartir distribuidor' }}
            </Button>
            <Button :disabled="compartiendo" @click="compartir(precioConIva)">
              <ShareIcon class="size-4" />
              {{ compartiendo ? 'Compartiendo...' : 'Compartir' }}
            </Button>
          </div>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
