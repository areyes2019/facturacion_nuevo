<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import http from '../lib/http'
import type { Catalogo } from '../stores/catalogos'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select'

const modelValue = defineModel<number | null>({ default: null })

/**
 * El catálogo elegido completo, no solo su `id`.
 *
 * La carga masiva por pasos (023) necesita su `articulos_count` para saber si el paso de imágenes
 * tiene contra qué emparejar. Se emite como evento aparte, en vez de cambiar el `v-model`, para que
 * los demás formularios que ya usan este componente puedan seguir ignorándolo.
 */
const emit = defineEmits<{ seleccionado: [Catalogo | null] }>()

const opciones = ref<Catalogo[]>([])

async function cargar() {
  const { data } = await http.get('/catalogos-proveedor', { params: { per_page: 100 } })
  opciones.value = data.data
  emitirSeleccionado()
}

function emitirSeleccionado() {
  emit('seleccionado', opciones.value.find((opcion) => opcion.id === modelValue.value) ?? null)
}

onMounted(cargar)

watch(modelValue, emitirSeleccionado)

/**
 * Deja releer el conteo de artículos sin cerrar el modal que contenga el componente: después de
 * importar un CSV, el catálogo elegido dejó de estar vacío y el paso de imágenes debe desbloquearse
 * ahí mismo (ver 023-carga-masiva-por-pasos.md).
 */
defineExpose({ recargar: cargar })
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
