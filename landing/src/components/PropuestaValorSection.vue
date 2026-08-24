<script setup lang="ts">
const beneficios = [
  { titulo: 'Disponibilidad', texto: 'Productos e insumos para mantener tu operación en marcha.' },
  { titulo: 'Atención directa', texto: 'Habla directamente con nosotros por WhatsApp.' },
  {
    titulo: 'Enfoque B2B',
    texto: 'Entendemos las necesidades de imprentas, fabricantes y distribuidores.',
  },
  { titulo: 'Cercanía', texto: 'Un proveedor con atención humana, sin procesos complicados.' },
]
</script>

<template>
  <section class="bg-muted py-20">
    <div class="mx-auto max-w-6xl px-4">
      <h2 class="font-heading text-center text-3xl font-bold sm:text-4xl">
        Un proveedor pensado para tu negocio.
      </h2>

      <div class="mt-10 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <div
          v-for="beneficio in beneficios"
          :key="beneficio.titulo"
          class="border-border rounded-xl border bg-white p-6"
        >
          <h3 class="text-primary font-heading text-lg font-bold">{{ beneficio.titulo }}</h3>
          <p class="text-muted-foreground mt-2 text-sm">{{ beneficio.texto }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
