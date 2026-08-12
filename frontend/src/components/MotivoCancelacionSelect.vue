<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '../lib/http'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'

const modelValue = defineModel<string | null>({ default: null })

const opciones = ref<{ id: string; texto: string }[]>([])

onMounted(async () => {
  const { data } = await http.get('/catalogos/motivos-cancelacion')
  opciones.value = data.data
})
</script>

<template>
  <Select v-model="modelValue">
    <SelectTrigger class="w-full">
      <SelectValue placeholder="Selecciona un motivo de cancelación" />
    </SelectTrigger>
    <SelectContent>
      <SelectItem v-for="opcion in opciones" :key="opcion.id" :value="opcion.id">
        {{ opcion.id }} - {{ opcion.texto }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
