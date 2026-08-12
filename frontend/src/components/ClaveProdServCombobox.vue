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

const modelValue = defineModel<string | null>({ default: null })

const resultados = ref<{ id: string; texto: string }[]>([])
const buscando = ref(false)

const buscar = useDebounceFn(async (texto: string) => {
  if (texto.trim().length < 2) {
    resultados.value = []
    return
  }

  buscando.value = true
  try {
    const { data } = await http.get('/catalogos/claves-prod-serv', { params: { q: texto } })
    resultados.value = data.data
  } finally {
    buscando.value = false
  }
}, 300)
</script>

<template>
  <Combobox v-model="modelValue" ignore-filter class="w-full">
    <ComboboxAnchor class="w-full">
      <ComboboxInput
        class="w-full"
        placeholder="Buscar clave de producto o servicio..."
        :display-value="(v: unknown) => (typeof v === 'string' ? v : '')"
        @update:model-value="buscar($event as string)"
      />
    </ComboboxAnchor>
    <ComboboxList class="w-full">
      <ComboboxViewport>
        <ComboboxEmpty v-if="!buscando">
          {{ resultados.length === 0 ? 'Escribe al menos 2 caracteres para buscar.' : '' }}
        </ComboboxEmpty>
        <ComboboxItem v-for="item in resultados" :key="item.id" :value="item.id">
          {{ item.id }} - {{ item.texto }}
        </ComboboxItem>
      </ComboboxViewport>
    </ComboboxList>
  </Combobox>
</template>
