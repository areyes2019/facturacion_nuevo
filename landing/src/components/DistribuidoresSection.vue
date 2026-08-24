<script setup lang="ts">
import { enlaceWhatsapp } from '@/lib/whatsapp'
</script>

<template>
  <section class="bg-primary py-20 text-white">
    <div class="mx-auto max-w-3xl px-4 text-center">
      <h2 class="font-heading text-3xl font-bold sm:text-4xl">
        ¿Vendes sellos? Podemos ser tu proveedor.
      </h2>
      <p class="mt-4 text-lg text-white/90">
        Si comercializas sellos o insumos, podemos ayudarte a mantener abastecido tu negocio.
      </p>
      <a
        :href="enlaceWhatsapp('distribuidor')"
        target="_blank"
        rel="noopener"
        class="bg-accent text-accent-foreground mt-8 inline-flex items-center justify-center rounded-full px-6 py-3 text-base font-semibold shadow-lg transition hover:brightness-95"
      >
        Quiero información para distribuir
      </a>
    </div>
  </section>
</template>
