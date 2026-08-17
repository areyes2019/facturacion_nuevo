<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useTemplateRef } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import { PhotoIcon } from '@heroicons/vue/24/outline'
import http from '../../lib/http'
import { mensajeDeFalla } from '../../lib/errors'
import { useScrollInfinito } from '../../lib/scrollInfinito'
import { listaRecordada, recordarLista } from '../../lib/memoriaLista'
import { imagenUrl, type Articulo } from '../../stores/articulos'
import AppLayout from '../../layouts/AppLayout.vue'
import { Alert, AlertDescription } from '../../components/ui/alert'
import { Input } from '../../components/ui/input'

/**
 * El catálogo del mostrador (ver 031-mostrador-consulta.md).
 *
 * Las mismas tarjetas del paso de artículos, pero **tocar una no agrega nada a ningún carrito**:
 * abre la ficha. Como es la misma tarjeta que allá suma una unidad, la pantalla tiene que dejarlo
 * claro sin que haya que probarlo — por eso aquí no hay barra de carrito al pie ni contadores
 * encima de las tarjetas.
 *
 * **El precio va con IVA**, al revés que en la captura: allá el número forma un renglón del
 * documento y el IVA se desglosa después; acá es el que el cliente escucha.
 */

const CLAVE = 'mostrador-catalogo'

const texto = ref('')
const articulos = ref<Articulo[]>([])
const pagina = ref(1)
const ultimaPagina = ref(1)
const cargando = ref(false)
const error = ref<string | null>(null)

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

const recordada = listaRecordada<Articulo>(CLAVE)

if (recordada) {
  articulos.value = recordada.items
  pagina.value = recordada.pagina
  ultimaPagina.value = recordada.ultimaPagina
  texto.value = recordada.texto

  void nextTick(() => window.scrollTo(0, recordada.scrollY))
} else {
  void cargar(1)
}

onBeforeUnmount(() => {
  recordarLista<Articulo>(CLAVE, {
    items: articulos.value,
    pagina: pagina.value,
    ultimaPagina: ultimaPagina.value,
    texto: texto.value,
    scrollY: window.scrollY,
  })
})

const buscar = useDebounceFn(() => cargar(1), 300)

const centinela = useTemplateRef<HTMLElement>('centinela')

useScrollInfinito(centinela, () => {
  if (pagina.value < ultimaPagina.value) void cargar(pagina.value + 1)
})
</script>

<template>
  <AppLayout mostrador barra>
    <div class="mx-auto max-w-md space-y-4">
      <h1 class="font-heading text-foreground text-xl font-semibold">Catálogo</h1>

      <Input
        v-model="texto"
        placeholder="Buscar artículo..."
        class="h-12 text-base"
        @update:model-value="buscar()"
      />

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <p v-if="!cargando && articulos.length === 0" class="text-muted-foreground py-8 text-center">
        No hay artículos que coincidan.
      </p>

      <ul class="space-y-2">
        <li v-for="articulo in articulos" :key="articulo.id">
          <RouterLink
            :to="{ name: 'mostrador-articulo-ver', params: { id: articulo.id } }"
            class="border-border bg-background hover:bg-accent focus-visible:ring-ring flex w-full items-center gap-3 rounded-lg border p-3 focus-visible:ring-2 focus-visible:outline-none"
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
              <p class="text-foreground font-semibold tabular-nums">
                ${{ articulo.precio_unitario_con_iva.toFixed(2) }}
              </p>
            </div>
          </RouterLink>
        </li>
      </ul>

      <div v-if="cargando" class="text-muted-foreground py-4 text-center">Cargando...</div>
      <div v-else-if="pagina < ultimaPagina" ref="centinela" class="h-px"></div>
    </div>
  </AppLayout>
</template>
