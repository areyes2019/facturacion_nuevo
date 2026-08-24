<script setup lang="ts">
import { enlaceWhatsapp } from '@/lib/whatsapp'

const productos = [
  { nombre: 'Sellos', texto: 'Diferentes soluciones para negocios y clientes finales.', img: '/img/sellos.webp' },
  { nombre: 'Mecanismos', texto: 'Opciones para fabricación y reposición.', img: '/img/mecanismos.jpg' },
  { nombre: 'Fechadores', texto: 'Soluciones para oficinas y negocios.', img: '/img/fechadores.jpg' },
  {
    nombre: 'Tintas y almohadillas',
    texto: 'Insumos para mantener los sellos funcionando.',
    img: '/img/tintas-almohadillas.webp',
  },
  { nombre: 'Materiales', texto: 'Productos necesarios para fabricación.', img: '/img/materiales.jpg' },
  {
    nombre: 'Soluciones especiales',
    texto: 'Productos para necesidades específicas.',
    img: '/img/soluciones-especiales.jpg',
  },
]
</script>

<template>
  <section id="soluciones" class="mx-auto max-w-6xl px-4 py-20">
    <h2 class="font-heading text-center text-3xl font-bold sm:text-4xl">
      Lo que necesitas para trabajar
    </h2>

    <div class="mt-10 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
      <article
        v-for="producto in productos"
        :key="producto.nombre"
        class="border-border overflow-hidden rounded-xl border bg-white shadow-sm"
      >
        <img
          :src="producto.img"
          :alt="producto.nombre"
          loading="lazy"
          class="h-48 w-full object-cover"
        />
        <div class="p-5">
          <h3 class="font-heading text-lg font-bold">{{ producto.nombre }}</h3>
          <p class="text-muted-foreground mt-1 text-sm">{{ producto.texto }}</p>
        </div>
      </article>
    </div>

    <div class="mt-12 overflow-hidden rounded-2xl">
      <img
        src="/img/composicion.webp"
        alt="Composición de sellos y mecanismos Prosello"
        loading="lazy"
        class="h-64 w-full object-cover sm:h-80"
      />
    </div>

    <div class="mt-10 text-center">
      <p class="font-heading text-lg font-semibold">¿Buscas algo específico? Pregúntanos.</p>
      <a
        :href="enlaceWhatsapp('producto')"
        target="_blank"
        rel="noopener"
        class="bg-whatsapp text-whatsapp-foreground mt-4 inline-flex items-center justify-center rounded-full px-6 py-3 text-base font-semibold shadow-sm transition hover:brightness-95"
      >
        Consultar por WhatsApp
      </a>
    </div>
  </section>
</template>
