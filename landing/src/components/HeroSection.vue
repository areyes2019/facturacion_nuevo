<script setup lang="ts">
import { enlaceWhatsapp } from '@/lib/whatsapp'
</script>

<template>
  <section id="inicio" class="relative overflow-hidden">
    <div class="absolute inset-0">
      <img
        src="/img/hero.webp"
        alt="Sellos y mecanismos Prosello"
        class="h-full w-full object-cover"
      />
      <div class="from-primary/95 via-primary/85 absolute inset-0 bg-gradient-to-r to-transparent" />
    </div>

    <div class="relative mx-auto max-w-6xl px-4 py-24 sm:py-32">
      <div class="max-w-xl">
        <h1 class="font-heading text-4xl font-extrabold text-white sm:text-5xl">
          Sellos e insumos para quienes viven de vender sellos.
        </h1>
        <p class="mt-5 text-lg text-white/90">
          Abastece tu imprenta, fabricación o negocio con mecanismos, sellos e insumos de
          proveedores especializados.
        </p>

        <div class="mt-8 flex flex-wrap gap-4">
          <a
            :href="enlaceWhatsapp('general')"
            target="_blank"
            rel="noopener"
            class="bg-whatsapp text-whatsapp-foreground inline-flex items-center justify-center rounded-full px-6 py-3 text-base font-semibold shadow-lg transition hover:brightness-95"
          >
            Hablar por WhatsApp
          </a>
          <a
            href="#soluciones"
            class="inline-flex items-center justify-center rounded-full border border-white/60 px-6 py-3 text-base font-semibold text-white transition hover:bg-white/10"
          >
            Conocer más
          </a>
        </div>
      </div>
    </div>

    <p
      class="bg-accent text-accent-foreground relative py-3 text-center text-sm font-semibold sm:text-base"
    >
      Tu negocio necesita un proveedor. Nosotros estamos para surtirte.
    </p>
  </section>
</template>
