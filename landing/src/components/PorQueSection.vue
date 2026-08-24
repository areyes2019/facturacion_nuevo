<script setup lang="ts">
const razones = [
  'Atención directa',
  'Especialización',
  'Soluciones para profesionales',
  'Disponibilidad',
  'Cercanía',
]
</script>

<template>
  <section id="nosotros" class="bg-muted py-20">
    <div class="mx-auto max-w-4xl px-4">
      <h2 class="font-heading text-center text-3xl font-bold sm:text-4xl">
        ¿Por qué trabajar con nosotros?
      </h2>

      <div class="mt-10 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div
          v-for="razon in razones"
          :key="razon"
          class="border-border flex items-center gap-3 rounded-xl border bg-white p-4"
        >
          <span class="bg-accent/15 text-accent flex h-9 w-9 shrink-0 items-center justify-center rounded-full">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
          </span>
          <span class="font-medium">{{ razon }}</span>
        </div>
      </div>
    </div>
  </section>
</template>
