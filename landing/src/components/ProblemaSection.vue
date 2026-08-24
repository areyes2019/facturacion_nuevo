<script setup lang="ts">
import { enlaceWhatsapp } from '@/lib/whatsapp'

const preguntas = ['¿Lo tienen?', '¿Me pueden surtir?', '¿Cómo los contacto?']
</script>

<template>
  <section class="mx-auto max-w-6xl px-4 py-20">
    <div class="mx-auto max-w-2xl text-center">
      <h2 class="font-heading text-3xl font-bold sm:text-4xl">
        Sabemos lo que pasa cuando te falta un insumo.
      </h2>
      <p class="text-muted-foreground mt-4 text-lg">
        Cuando tienes una orden pendiente y te falta un mecanismo, una almohadilla o un insumo,
        necesitas una solución rápida. No necesitas recorrer diez páginas buscando un producto.
        Necesitas saber:
      </p>

      <div class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <p
          v-for="pregunta in preguntas"
          :key="pregunta"
          class="border-border bg-muted rounded-xl border px-4 py-5 font-heading text-lg font-semibold"
        >
          {{ pregunta }}
        </p>
      </div>

      <a
        :href="enlaceWhatsapp('general')"
        target="_blank"
        rel="noopener"
        class="bg-whatsapp text-whatsapp-foreground mt-8 inline-flex items-center justify-center rounded-full px-6 py-3 text-base font-semibold shadow-sm transition hover:brightness-95"
      >
        Pregúntanos por WhatsApp
      </a>
    </div>
  </section>
</template>
