<script setup lang="ts">
import { ChevronDownIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'

/**
 * Cabecera que ordena por su columna (ver 011-precio-proveedor-utilidad.md).
 *
 * Vive aparte para que el `<thead>` del listado no cargue con el estado del control —qué flecha va,
 * qué dice `aria-sort`— repartido entre sus columnas
 * (ver 025-filtros-columna-listado-articulos.md).
 */
defineProps<{ etiqueta: string; activa: boolean; direccion: 'asc' | 'desc' }>()

defineEmits<{ ordenar: [] }>()
</script>

<template>
  <button
    type="button"
    class="hover:text-foreground -mx-1 flex items-center gap-1 rounded px-1 py-0.5"
    :aria-sort="activa ? (direccion === 'asc' ? 'ascending' : 'descending') : 'none'"
    @click="$emit('ordenar')"
  >
    {{ etiqueta }}
    <ChevronUpIcon v-if="activa && direccion === 'asc'" class="size-3.5" />
    <ChevronDownIcon v-else-if="activa" class="size-3.5" />
  </button>
</template>
