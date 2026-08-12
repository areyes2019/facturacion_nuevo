<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { PencilIcon, ShareIcon, PhotoIcon } from '@heroicons/vue/24/outline'
import { imagenUrl, type Articulo } from '../stores/articulos'
import http from '../lib/http'
import { Button } from './ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from './ui/dialog'

/**
 * Ficha visual de un artículo (ver 020-imagenes-articulos.md): foto grande a la izquierda, datos a
 * la derecha y el botón de compartir abajo.
 *
 * Es lo que se le enseña o se le manda a un cliente, así que muestra el precio de venta con IVA y
 * **nunca** el precio del proveedor, el costo ni la utilidad.
 */
const props = defineProps<{ articulo: Articulo | null }>()
const emit = defineEmits<{ 'update:open': [boolean] }>()

const compartiendo = ref(false)
const aviso = ref<string | null>(null)

const url = computed(() => (props.articulo ? imagenUrl(props.articulo) : null))

const precioConIva = computed(() =>
  props.articulo ? `$${props.articulo.precio_unitario_con_iva.toFixed(2)}` : '',
)

const textoCompartible = computed(() =>
  props.articulo
    ? `${props.articulo.nombre} — Modelo ${props.articulo.modelo} — ${precioConIva.value}`
    : '',
)

watch(
  () => props.articulo,
  () => {
    aviso.value = null
  },
)

/**
 * Convierte la imagen a JPEG en el navegador.
 *
 * El archivo guardado es WEBP, y **WhatsApp trata los `.webp` como calcomanías**: compartir el
 * original le llegaría al cliente como sticker en vez de como la foto del producto. Convertir aquí
 * evita guardar una segunda copia de cada imagen en el servidor solo para este caso.
 */
async function comoJpeg(blob: Blob): Promise<Blob> {
  const bitmap = await createImageBitmap(blob)
  const canvas = document.createElement('canvas')
  canvas.width = bitmap.width
  canvas.height = bitmap.height

  const contexto = canvas.getContext('2d')
  if (!contexto) throw new Error('sin canvas')

  // Fondo blanco: un WEBP con transparencia quedaría con el fondo negro al pasar a JPEG, que no
  // tiene canal alfa.
  contexto.fillStyle = '#ffffff'
  contexto.fillRect(0, 0, canvas.width, canvas.height)
  contexto.drawImage(bitmap, 0, 0)
  bitmap.close()

  return await new Promise<Blob>((resolve, reject) => {
    canvas.toBlob(
      (resultado) => (resultado ? resolve(resultado) : reject(new Error('sin blob'))),
      'image/jpeg',
      0.9,
    )
  })
}

/**
 * En celular abre el menú del propio aparato con la foto y el texto; en escritorio copia el texto.
 *
 * La decisión se toma preguntándole al navegador si puede compartir archivos, no por el ancho de la
 * pantalla: en escritorio muchos navegadores no tienen ese menú, y un botón que a veces hace algo y
 * a veces no es peor que dos comportamientos claros.
 */
async function compartir() {
  if (!props.articulo) return

  compartiendo.value = true
  aviso.value = null

  try {
    if (url.value && typeof navigator.canShare === 'function') {
      const { data } = await http.get(`/articulos/${props.articulo.id}/imagen`, {
        responseType: 'blob',
      })

      const archivo = new File([await comoJpeg(data)], `${props.articulo.modelo}.jpg`, {
        type: 'image/jpeg',
      })

      if (navigator.canShare({ files: [archivo] })) {
        await navigator.share({ text: textoCompartible.value, files: [archivo] })

        return
      }
    }

    await navigator.clipboard.writeText(textoCompartible.value)
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
              <p class="text-muted-foreground text-xs uppercase">Precio con IVA</p>
              <p class="text-2xl font-semibold tabular-nums">{{ precioConIva }}</p>
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
            <Button :disabled="compartiendo" @click="compartir">
              <ShareIcon class="size-4" />
              {{ compartiendo ? 'Compartiendo...' : 'Compartir' }}
            </Button>
          </div>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
