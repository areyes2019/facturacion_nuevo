<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, PhotoIcon, ShareIcon } from '@heroicons/vue/24/outline'
import http from '../../lib/http'
import { mensajeDeFalla, mensajeDeFallaDeDescarga } from '../../lib/errors'
import { compartirArchivo, compartirTexto, type ArchivoCompartible } from '../../lib/compartir'
import { comoJpeg } from '../../lib/imagenCompartible'
import { imagenUrl, type Articulo } from '../../stores/articulos'
import AppLayout from '../../layouts/AppLayout.vue'
import { Alert, AlertDescription } from '../../components/ui/alert'
import { Button } from '../../components/ui/button'

/**
 * La ficha de un artículo en el mostrador (ver 031-mostrador-consulta.md).
 *
 * **Pantalla completa, no un modal**: esta es la pantalla que se le voltea al cliente para que vea
 * el producto, así que tiene que ocupar el aparato entero, sin un marco alrededor ni el listado
 * asomándose atrás.
 *
 * Muestra foto, nombre, modelo y precio, y **nunca** el costo, el precio del proveedor, la utilidad
 * ni las existencias: con el teléfono en la mano del cliente, todo lo que esté en la pantalla es
 * información que se le dio (misma regla que la ficha del escritorio, ver 020).
 */

const route = useRoute()
const router = useRouter()

const articulo = ref<Articulo | null>(null)
const cargando = ref(true)
const error = ref<string | null>(null)

const aviso = ref<string | null>(null)
const preparando = ref(false)
/** La foto ya bajada y convertida, lista para el menú del aparato (ver 029, supuesto 78). */
const archivo = ref<ArchivoCompartible | null>(null)

const url = computed(() => (articulo.value ? imagenUrl(articulo.value) : null))

const precio = computed(() =>
  articulo.value ? `$${articulo.value.precio_unitario_con_iva.toFixed(2)}` : '',
)

// Rotular "con IVA" un artículo que no causa impuesto sería falso: ahí el precio a secas es el que
// paga el cliente (ver 024-precios-sin-centavos.md).
const etiquetaPrecio = computed(() =>
  articulo.value?.objeto_imp === '02' ? 'Precio con IVA' : 'Precio',
)

const texto = computed(() =>
  articulo.value
    ? `${articulo.value.nombre} — Modelo ${articulo.value.modelo} — ${precio.value}`
    : '',
)

async function cargar() {
  cargando.value = true
  error.value = null

  try {
    const { data } = await http.get(`/articulos/${route.params.id}`)
    articulo.value = data.data

    if (articulo.value?.tiene_imagen) void prepararFoto()
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    cargando.value = false
  }
}

void cargar()

/**
 * Baja la foto y la convierte **al entrar a la pantalla**, no al apretar el botón: el menú del
 * aparato solo se abre mientras el gesto del usuario sigue vivo, y esperar a la descarga lo agota.
 */
async function prepararFoto() {
  if (articulo.value === null) return

  preparando.value = true

  try {
    const { data } = await http.get(`/articulos/${articulo.value.id}/imagen`, {
      responseType: 'blob',
    })

    archivo.value = {
      contenido: await comoJpeg(data),
      nombre: `${articulo.value.modelo}.jpg`,
    }
  } catch (err) {
    // La ficha sigue sirviendo: sin foto lista, el botón manda el texto, que es lo que el cliente
    // necesita para pedirlo.
    aviso.value = await mensajeDeFallaDeDescarga(err)
  } finally {
    preparando.value = false
  }
}

/**
 * Con foto sale la foto y el texto por el menú del aparato; sin ella, solo el texto. Apagar el
 * botón porque falta una fotografía le quitaría a la ficha su única función.
 */
async function compartir() {
  if (articulo.value === null) return

  aviso.value = null

  try {
    const resultado =
      archivo.value !== null
        ? await compartirArchivo(archivo.value.contenido, archivo.value.nombre, texto.value)
        : await compartirTexto(texto.value)

    if (resultado === 'descargado') {
      aviso.value = 'Foto descargada: adjúntala en la ventana de WhatsApp que acaba de abrirse.'
    } else if (resultado === 'compartido') {
      aviso.value = 'Artículo compartido.'
    }
  } catch (err) {
    aviso.value = mensajeDeFalla(err)
  }
}
</script>

<template>
  <AppLayout mostrador barra>
    <div class="mx-auto max-w-md space-y-4">
      <Button variant="ghost" size="sm" class="-ml-2" @click="router.back()">
        <ArrowLeftIcon class="size-4" />
        Catálogo
      </Button>

      <p v-if="cargando" class="text-muted-foreground py-8 text-center">Cargando...</p>

      <Alert v-else-if="error" variant="destructive">
        <AlertDescription class="space-y-2">
          <p>{{ error }}</p>
          <Button type="button" size="sm" variant="outline" @click="cargar">Reintentar</Button>
        </AlertDescription>
      </Alert>

      <template v-else-if="articulo">
        <!-- La foto en grande, y el marcador del mismo tamaño cuando no hay: la ficha no cambia de
             forma según haya o no imagen. -->
        <div
          class="bg-muted flex aspect-square w-full items-center justify-center overflow-hidden rounded-lg"
        >
          <img v-if="url" :src="url" :alt="articulo.nombre" class="h-full w-full object-contain" />
          <div v-else class="text-muted-foreground flex flex-col items-center gap-2">
            <PhotoIcon class="size-12" />
            Sin imagen
          </div>
        </div>

        <div class="space-y-2">
          <h1 class="font-heading text-foreground text-xl leading-tight font-semibold break-words">
            {{ articulo.nombre }}
          </h1>
          <p class="text-muted-foreground">Modelo {{ articulo.modelo }}</p>
          <div>
            <p class="text-muted-foreground text-xs uppercase">{{ etiquetaPrecio }}</p>
            <p class="text-foreground text-4xl font-semibold tabular-nums">{{ precio }}</p>
          </div>
        </div>

        <Alert v-if="aviso">
          <AlertDescription>{{ aviso }}</AlertDescription>
        </Alert>

        <Button class="h-14 w-full text-base" :disabled="preparando" @click="compartir">
          <ShareIcon class="size-5" />
          {{ preparando ? 'Preparando...' : 'Compartir' }}
        </Button>
      </template>
    </div>
  </AppLayout>
</template>
