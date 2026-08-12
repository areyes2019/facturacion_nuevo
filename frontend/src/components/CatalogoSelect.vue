<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '../lib/http'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'

const modelValue = defineModel<number | null>({ default: null })

const opciones = ref<{ id: number; nombre: string; proveedor_nombre_comercial: string | null }[]>(
  [],
)

onMounted(async () => {
  const { data } = await http.get('/catalogos-proveedor', { params: { per_page: 100 } })
  opciones.value = data.data
})
</script>

<template>
  <Select
    :model-value="modelValue?.toString() ?? undefined"
    @update:model-value="(v) => (modelValue = v ? Number(v) : null)"
  >
    <SelectTrigger class="w-full">
      <SelectValue placeholder="Selecciona un catálogo" />
    </SelectTrigger>
    <SelectContent>
      <SelectItem v-for="opcion in opciones" :key="opcion.id" :value="opcion.id.toString()">
        {{ opcion.proveedor_nombre_comercial ?? '—' }} — {{ opcion.nombre }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
