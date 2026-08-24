<script setup lang="ts">
import { enlaceWhatsapp } from '@/lib/whatsapp'
</script>

<template>
  <section class="bg-accent py-20 text-white">
    <div class="mx-auto max-w-2xl px-4 text-center">
      <h2 class="font-heading text-3xl font-bold sm:text-4xl">¿Qué necesitas para tu negocio?</h2>
      <p class="mt-4 text-lg text-white/90">Cuéntanos qué estás buscando y te ayudamos.</p>
      <a
        :href="enlaceWhatsapp('general')"
        target="_blank"
        rel="noopener"
        class="bg-whatsapp text-whatsapp-foreground mt-8 inline-flex items-center justify-center rounded-full px-8 py-4 text-lg font-bold shadow-lg transition hover:brightness-95"
      >
        Hablar por WhatsApp
      </a>
    </div>
  </section>
</template>
