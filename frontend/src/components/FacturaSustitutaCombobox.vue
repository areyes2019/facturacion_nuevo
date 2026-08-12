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

interface FacturaResultado {
  id: number
  folio: number
  uuid_fiscal: string | null
}

const resultados = ref<FacturaResultado[]>([])
const buscando = ref(false)

const buscar = useDebounceFn(async (texto: string) => {
  buscando.value = true
  try {
    const { data } = await http.get('/facturas', {
      params: { search: texto || undefined, estado: 'timbrada' },
    })
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
    @update:model-value="(v) => (modelValue = v ? Number(v) : null)"
  >
    <ComboboxAnchor class="w-full">
      <ComboboxInput
        class="w-full"
        placeholder="Buscar factura sustituta por folio o UUID..."
        :display-value="
          (v: unknown) =>
            resultados.find((f) => f.id.toString() === v)?.uuid_fiscal ??
            (typeof v === 'string' ? v : '')
        "
        @update:model-value="buscar($event as string)"
        @focus="buscar('')"
      />
    </ComboboxAnchor>
    <ComboboxList class="w-full">
      <ComboboxViewport>
        <ComboboxEmpty v-if="!buscando">
          {{ resultados.length === 0 ? 'No hay facturas timbradas disponibles.' : '' }}
        </ComboboxEmpty>
        <ComboboxItem v-for="item in resultados" :key="item.id" :value="item.id.toString()">
          Folio {{ item.folio }} — {{ item.uuid_fiscal }}
        </ComboboxItem>
      </ComboboxViewport>
    </ComboboxList>
  </Combobox>
</template>
