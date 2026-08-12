<script setup lang="ts">
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'

const modelValue = defineModel<string>({ default: '16' })

withDefaults(defineProps<{ disabled?: boolean }>(), { disabled: false })

const opciones = [
  { id: '16', texto: '16%' },
  { id: '0', texto: '0%' },
  { id: 'exento', texto: 'Exento' },
]
</script>

<template>
  <Select v-model="modelValue" :disabled="disabled">
    <SelectTrigger class="w-full">
      <SelectValue placeholder="IVA" />
    </SelectTrigger>
    <SelectContent>
      <SelectItem v-for="opcion in opciones" :key="opcion.id" :value="opcion.id">
        {{ opcion.texto }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
