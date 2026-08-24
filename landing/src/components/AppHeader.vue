<script setup lang="ts">
import { ref } from 'vue'
import { enlaceWhatsapp } from '@/lib/whatsapp'

const menuAbierto = ref(false)

const enlaces = [
  { href: '#inicio', texto: 'Inicio' },
  { href: '#soluciones', texto: 'Soluciones' },
  { href: '#para-quien', texto: 'Para quién' },
  { href: '#nosotros', texto: 'Nosotros' },
  { href: '#contacto', texto: 'Contacto' },
]
</script>

<template>
  <header class="border-border bg-background/95 sticky top-0 z-50 border-b backdrop-blur">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
      <a href="#inicio" class="text-primary font-heading text-lg font-bold tracking-tight">
        Prosello <span class="text-accent">Distribuciones</span>
      </a>

      <nav class="hidden items-center gap-8 md:flex">
        <a
          v-for="enlace in enlaces"
          :key="enlace.href"
          :href="enlace.href"
          class="text-muted-foreground hover:text-primary text-sm font-medium transition-colors"
        >
          {{ enlace.texto }}
        </a>
      </nav>

      <div class="flex items-center gap-3">
        <a
          :href="enlaceWhatsapp('general')"
          target="_blank"
          rel="noopener"
          class="bg-whatsapp text-whatsapp-foreground hidden items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold shadow-sm transition hover:brightness-95 sm:inline-flex"
        >
          WhatsApp
        </a>

        <button
          type="button"
          class="text-foreground inline-flex h-10 w-10 items-center justify-center rounded-lg md:hidden"
          aria-label="Abrir menú"
          @click="menuAbierto = !menuAbierto"
        >
          <svg
            v-if="!menuAbierto"
            xmlns="http://www.w3.org/2000/svg"
            class="h-6 w-6"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
          <svg
            v-else
            xmlns="http://www.w3.org/2000/svg"
            class="h-6 w-6"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <nav v-if="menuAbierto" class="border-border bg-background border-t md:hidden">
      <div class="mx-auto flex max-w-6xl flex-col px-4 py-2">
        <a
          v-for="enlace in enlaces"
          :key="enlace.href"
          :href="enlace.href"
          class="text-foreground border-border border-b py-3 text-base font-medium last:border-none"
          @click="menuAbierto = false"
        >
          {{ enlace.texto }}
        </a>
        <a
          :href="enlaceWhatsapp('general')"
          target="_blank"
          rel="noopener"
          class="bg-whatsapp text-whatsapp-foreground my-3 inline-flex items-center justify-center gap-2 rounded-full px-4 py-3 text-sm font-semibold"
        >
          Hablar por WhatsApp
        </a>
      </div>
    </nav>
  </header>
</template>
