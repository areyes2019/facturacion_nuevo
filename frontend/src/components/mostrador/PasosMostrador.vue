<script setup lang="ts">
/**
 * El paso actual, señalado arriba de la captura (ver 029-pwa-mostrador.md).
 *
 * Una pantalla por paso solo se entiende si se ve dónde va uno y cuánto falta; sin eso, "Siguiente"
 * es un botón que lleva a un lugar desconocido.
 */
defineProps<{ pasos: string[]; actual: number }>()
</script>

<template>
  <div class="space-y-2">
    <p class="text-muted-foreground text-sm font-medium">
      Paso {{ actual + 1 }} de {{ pasos.length }} · {{ pasos[actual] }}
    </p>
    <div class="flex gap-1.5" aria-hidden="true">
      <span
        v-for="(paso, i) in pasos"
        :key="paso"
        class="h-1.5 flex-1 rounded-full"
        :class="i <= actual ? 'bg-primary' : 'bg-muted-foreground/25'"
      ></span>
    </div>
  </div>
</template>
