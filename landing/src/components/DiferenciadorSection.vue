<script setup lang="ts">
import { enlaceWhatsapp } from '@/lib/whatsapp'
</script>

<template>
  <section class="bg-foreground py-20 text-white">
    <div class="mx-auto max-w-2xl px-4 text-center">
      <h2 class="font-heading text-3xl font-bold sm:text-4xl">
        No queremos ser otro proveedor más.
      </h2>
      <p class="mt-4 text-lg text-white/80">
        Queremos ser ese proveedor al que puedas escribir cuando necesitas resolver algo.
      </p>

      <p class="font-heading mt-8 text-4xl font-extrabold sm:text-5xl">
        "¿Lo necesitas? Pregúntanos."
      </p>
      <p class="mt-3 text-white/80">Te atendemos directamente por WhatsApp.</p>

      <a
        :href="enlaceWhatsapp('general')"
        target="_blank"
        rel="noopener"
        class="bg-whatsapp text-whatsapp-foreground mt-8 inline-flex items-center justify-center rounded-full px-6 py-3 text-base font-semibold shadow-lg transition hover:brightness-95"
      >
        Hablar con Prosello
      </a>
    </div>
  </section>
</template>
