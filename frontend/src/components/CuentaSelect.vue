<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '../lib/http'
import type { Cuenta } from '../stores/cuentas'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'

const props = withDefaults(
  defineProps<{
    /**
     * Solo cuentas activas: una cuenta inactiva no admite movimientos nuevos, así que no debe
     * ofrecerse en los formularios de captura. Los filtros de consulta sí las incluyen.
     */
    soloActivas?: boolean
    placeholder?: string
  }>(),
  { soloActivas: true, placeholder: 'Selecciona una cuenta' },
)

const modelValue = defineModel<number | null>({ default: null })

const opciones = ref<Cuenta[]>([])

onMounted(async () => {
  const { data } = await http.get('/cuentas', {
    params: { per_page: 100, activa: props.soloActivas ? 'true' : undefined },
  })
  opciones.value = data.data
})
</script>

<template>
  <Select
    :model-value="modelValue?.toString() ?? undefined"
    @update:model-value="(v) => (modelValue = v ? Number(v) : null)"
  >
    <SelectTrigger class="w-full">
      <SelectValue :placeholder="placeholder" />
    </SelectTrigger>
    <SelectContent>
      <SelectItem v-for="opcion in opciones" :key="opcion.id" :value="opcion.id.toString()">
        {{ opcion.nombre }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
