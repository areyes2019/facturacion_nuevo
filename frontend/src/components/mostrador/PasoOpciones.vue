<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue'
import { CheckIcon } from '@heroicons/vue/24/outline'
import http from '../../lib/http'
import { mensajeDeFalla } from '../../lib/errors'
import { useScrollInfinito } from '../../lib/scrollInfinito'
import { Alert, AlertDescription } from '../ui/alert'
import { Input } from '../ui/input'

/**
 * Una opción de un catálogo del SAT, elegida en su propia pantalla (ver 029-pwa-mostrador.md).
 *
 * La usan el paso de uso de CFDI y el de forma de pago, que son la misma pantalla con otra lista:
 * escritas dos veces se verían distintas el día que una de las dos se corrigiera.
 *
 * El catálogo entero llega en **una sola petición** y se muestra de 15 en 15; el buscador filtra
 * aquí, sin volver al servidor. Es la diferencia con las listas de clientes y artículos, y es a
 * propósito: estos son catálogos cerrados de unas dos docenas de entradas, así que una petición por
 * scroll y otra por letra serían peticiones por nada.
 */

const props = defineProps<{
  /** Endpoint del catálogo, que responde `{ data: [{ id, texto }] }`. */
  url: string
  /** Lo que se busca, para el marcador de posición del buscador. */
  placeholder: string
}>()

export interface OpcionCatalogo {
  id: string
  texto: string
}

const emit = defineEmits<{ elegido: [OpcionCatalogo] }>()

/** La opción ya elegida, que se ve marcada al volver atrás. */
const elegida = defineModel<string | null>({ default: null })

const POR_TANDA = 15

const texto = ref('')
const opciones = ref<OpcionCatalogo[]>([])
const mostradas = ref(POR_TANDA)
const cargando = ref(true)
const error = ref<string | null>(null)

const filtradas = computed(() => {
  const busqueda = texto.value.trim().toLowerCase()

  if (busqueda === '') return opciones.value

  return opciones.value.filter(
    (opcion) =>
      opcion.id.toLowerCase().includes(busqueda) || opcion.texto.toLowerCase().includes(busqueda),
  )
})

const visibles = computed(() => filtradas.value.slice(0, mostradas.value))

// Buscar arranca la lista de nuevo: dejarla en la tanda anterior mostraría media búsqueda.
watch(texto, () => (mostradas.value = POR_TANDA))

async function cargar() {
  cargando.value = true
  error.value = null

  try {
    const { data } = await http.get(props.url)
    opciones.value = data.data
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    cargando.value = false
  }
}

void cargar()

const centinela = useTemplateRef<HTMLElement>('centinela')

useScrollInfinito(centinela, () => {
  if (mostradas.value < filtradas.value.length) mostradas.value += POR_TANDA
})

/** Tocar elige y avanza: apretar un "Siguiente" después de haber tocado no decide nada. */
function elegir(opcion: OpcionCatalogo) {
  elegida.value = opcion.id
  emit('elegido', opcion)
}
</script>

<template>
  <div class="space-y-4">
    <Input v-model="texto" :placeholder="placeholder" class="h-12 text-base" />

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <p v-if="cargando" class="text-muted-foreground py-8 text-center">Cargando...</p>

    <p v-else-if="filtradas.length === 0" class="text-muted-foreground py-8 text-center">
      Nada coincide con lo que escribiste.
    </p>

    <ul class="space-y-2">
      <li v-for="opcion in visibles" :key="opcion.id">
        <button
          type="button"
          class="border-border bg-background hover:bg-accent focus-visible:ring-ring flex w-full items-center gap-3 rounded-lg border p-4 text-left focus-visible:ring-2 focus-visible:outline-none"
          :class="opcion.id === elegida ? 'border-primary' : ''"
          @click="elegir(opcion)"
        >
          <div class="min-w-0 flex-1">
            <p class="text-foreground font-mono font-medium">{{ opcion.id }}</p>
            <p class="text-muted-foreground text-sm">{{ opcion.texto }}</p>
          </div>
          <CheckIcon v-if="opcion.id === elegida" class="text-primary size-6 shrink-0" />
        </button>
      </li>
    </ul>

    <div v-if="visibles.length < filtradas.length" ref="centinela" class="h-px"></div>
  </div>
</template>
