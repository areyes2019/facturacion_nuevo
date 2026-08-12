<script setup lang="ts">
import { ref } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import http from '../lib/http'
import {
  Combobox,
  ComboboxAnchor,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
  ComboboxViewport,
} from './ui/combobox'

const modelValue = defineModel<number | null>({ default: null })

export interface ClienteResultado {
  id: number
  razon_social: string
  rfc: string
  descuento_permanente: number
}

/**
 * Además del id, emite el cliente completo al elegirlo: el formulario de cotización necesita su
 * descuento permanente (ver 015-descuento-permanente-cliente.md) y el objeto ya viajó en la
 * respuesta de la búsqueda, así que evita una segunda petición. Los demás consumidores lo ignoran.
 */
const emit = defineEmits<{ seleccion: [ClienteResultado | null] }>()

function onSeleccion(valor: string | null) {
  // El id se toma del parámetro, no de `modelValue.value`: cuando el padre usa `v-model`, escribir
  // un ref de `defineModel` no actualiza su valor local hasta el siguiente ciclo de render, así que
  // leerlo aquí devolvería el cliente anterior (ver 003-design-system-tailwind.md).
  const id = valor ? Number(valor) : null
  modelValue.value = id
  emit('seleccion', resultados.value.find((c) => c.id === id) ?? null)
}

const resultados = ref<ClienteResultado[]>([])
const buscando = ref(false)

const buscar = useDebounceFn(async (texto: string) => {
  if (texto.trim().length < 2) {
    resultados.value = []
    return
  }

  buscando.value = true
  try {
    const { data } = await http.get('/clientes', { params: { search: texto } })
    resultados.value = data.data
  } finally {
    buscando.value = false
  }
}, 300)
</script>

<template>
  <Combobox
    :model-value="modelValue?.toString() ?? null"
    ignore-filter
    class="w-full"
    @update:model-value="(v) => onSeleccion(typeof v === 'string' ? v : null)"
  >
    <ComboboxAnchor class="w-full">
      <ComboboxInput
        class="w-full"
        placeholder="Buscar cliente por razón social o RFC..."
        :display-value="
          (v: unknown) =>
            resultados.find((c) => c.id.toString() === v)?.razon_social ??
            (typeof v === 'string' ? v : '')
        "
        @update:model-value="buscar($event as string)"
      />
    </ComboboxAnchor>
    <ComboboxList class="w-full">
      <ComboboxViewport>
        <ComboboxEmpty v-if="!buscando">
          {{ resultados.length === 0 ? 'Escribe al menos 2 caracteres para buscar.' : '' }}
        </ComboboxEmpty>
        <ComboboxItem v-for="item in resultados" :key="item.id" :value="item.id.toString()">
          {{ item.razon_social }} ({{ item.rfc }})
        </ComboboxItem>
      </ComboboxViewport>
    </ComboboxList>
  </Combobox>
</template>
