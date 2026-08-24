<script setup lang="ts">
import { enlaceWhatsapp } from '@/lib/whatsapp'

const publicos = [
  {
    nombre: 'Imprentas',
    texto: 'Amplía tus soluciones y atiende las necesidades de tus clientes.',
    img: '/img/mecanismos.jpg',
    origen: 'imprenta' as const,
  },
  {
    nombre: 'Fabricantes de sellos',
    texto: 'Encuentra mecanismos e insumos para mantener tu producción activa.',
    img: '/img/sellos.webp',
    origen: 'producto' as const,
  },
  {
    nombre: 'Distribuidores',
    texto: 'Abastece tu negocio con un proveedor especializado.',
    img: '/img/fechadores.jpg',
    origen: 'distribuidor' as const,
  },
]
</script>

<template>
  <section id="para-quien" class="mx-auto max-w-6xl px-4 py-20">
    <h2 class="font-heading text-center text-3xl font-bold sm:text-4xl">
      Trabajamos con quienes hacen que los sellos sucedan.
    </h2>

    <div class="mt-10 grid grid-cols-1 gap-6 sm:grid-cols-3">
      <a
        v-for="publico in publicos"
        :key="publico.nombre"
        :href="enlaceWhatsapp(publico.origen)"
        target="_blank"
        rel="noopener"
        class="border-border group overflow-hidden rounded-xl border bg-white shadow-sm transition hover:shadow-md"
      >
        <img
          :src="publico.img"
          :alt="publico.nombre"
          loading="lazy"
          class="h-40 w-full object-cover transition group-hover:scale-105"
        />
        <div class="p-5">
          <h3 class="text-primary font-heading text-lg font-bold">{{ publico.nombre }}</h3>
          <p class="text-muted-foreground mt-2 text-sm">{{ publico.texto }}</p>
        </div>
      </a>
    </div>
  </section>
</template>
