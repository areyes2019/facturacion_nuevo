<script setup lang="ts">
import { ChevronDownIcon, ChevronUpIcon } from '@heroicons/vue/24/outline'

/**
 * Cabecera que ordena por su columna (ver 011-precio-proveedor-utilidad.md).
 *
 * Se extrajo al agregarse la columna `id` (ver 025-filtros-columna-listado-articulos.md): `id` va al
 * principio de la tabla y las columnas de dinero al final, así que el mismo control tenía que
 * dibujarse en dos lugares del `<thead>` y duplicar el marcado habría dejado dos aria-sort que
 * mantener en sincronía.
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
