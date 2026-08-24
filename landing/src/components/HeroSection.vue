<script setup lang="ts">
import { enlaceWhatsapp } from '@/lib/whatsapp'
</script>

<template>
  <section id="inicio">
    <!-- El banner ya trae su propio mensaje y su propia gráfica (ver catalogo/banner.png), así
         que va sin overlay ni texto encima: cualquier título superpuesto compediría con el suyo. -->
    <img
      src="/img/banner.png"
      alt="Insumos para sellos. Todo lo que necesitas, cerca de ti. Compra desde 1 pieza, amplio surtido para fabricantes, entrega rápida en Celaya y Gto."
      class="w-full"
    />

    <div class="bg-muted">
      <div class="mx-auto max-w-6xl px-4 py-8">
        <!-- Título real para SEO/lectores de pantalla: el del banner es una imagen, no texto. -->
        <h1 class="sr-only">Sellos e insumos para quienes viven de vender sellos.</h1>

        <div class="flex flex-wrap justify-center gap-4">
          <a
            :href="enlaceWhatsapp('general')"
            target="_blank"
            rel="noopener"
            class="bg-whatsapp text-whatsapp-foreground inline-flex items-center justify-center rounded-full px-6 py-3 text-base font-semibold shadow-sm transition hover:brightness-95"
          >
            Hablar por WhatsApp
          </a>
          <a
            href="#soluciones"
            class="border-primary text-primary inline-flex items-center justify-center rounded-full border px-6 py-3 text-base font-semibold transition hover:bg-white"
          >
            Conocer más
          </a>
        </div>
      </div>
    </div>

    <p
      class="bg-accent text-accent-foreground py-3 text-center text-sm font-semibold sm:text-base"
    >
      Tu negocio necesita un proveedor. Nosotros estamos para surtirte.
    </p>
  </section>
</template>
