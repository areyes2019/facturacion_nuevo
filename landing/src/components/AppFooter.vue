<script setup lang="ts">
const enlaces = [
  { href: '#inicio', texto: 'Inicio' },
  { href: '#soluciones', texto: 'Soluciones' },
  { href: '#para-quien', texto: 'Para quién' },
  { href: '#nosotros', texto: 'Nosotros' },
  { href: '#contacto', texto: 'Contacto' },
]
</script>

<template>
  <footer class="bg-foreground py-10 text-white/80">
    <div class="mx-auto flex max-w-6xl flex-col items-center gap-4 px-4 text-center sm:flex-row sm:justify-between sm:text-left">
      <div>
        <p class="font-heading text-lg font-bold text-white">Prosello Distribuciones</p>
        <p class="text-sm">Celaya, Gto. — ventas@prosello.com.mx</p>
      </div>

      <nav class="flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm">
        <a v-for="enlace in enlaces" :key="enlace.href" :href="enlace.href" class="hover:text-white">
          {{ enlace.texto }}
        </a>
      </nav>
    </div>
  </footer>
</template>
