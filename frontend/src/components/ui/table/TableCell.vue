<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
  /**
   * Recorta el contenido con elipsis en vez de dejar que ensanche la fila sin límite (ver
   * 006-gestion-articulos.md). Combinar con un atributo `title` nativo para mostrar el texto
   * completo al pasar el mouse.
   */
  truncate?: boolean
}>()
</script>

<template>
  <td
    data-slot="table-cell"
    :class="
      cn(
        'p-2 align-middle whitespace-nowrap [&:has([role=checkbox])]:pr-0 *:[[role=checkbox]]:translate-y-0.5',
        props.class,
      )
    "
  >
    <span v-if="truncate" class="block max-w-[220px] truncate"><slot /></span>
    <slot v-else />
  </td>
</template>
