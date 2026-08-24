<script setup lang="ts">
import ContactoForm from '@/components/ContactoForm.vue'
import { enlaceWhatsapp } from '@/lib/whatsapp'
</script>

<template>
  <section id="contacto" class="mx-auto max-w-6xl px-4 py-20">
    <h2 class="font-heading text-center text-3xl font-bold sm:text-4xl">Hablemos</h2>

    <div class="mt-10 grid grid-cols-1 gap-10 lg:grid-cols-2">
      <div class="space-y-5">
        <a
          :href="enlaceWhatsapp('general')"
          target="_blank"
          rel="noopener"
          class="border-border hover:border-primary flex items-center gap-4 rounded-xl border bg-white p-4 transition"
        >
          <span class="bg-whatsapp/10 text-whatsapp flex h-11 w-11 shrink-0 items-center justify-center rounded-full">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
              <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.71.45 3.38 1.3 4.85L2 22l5.35-1.4a9.9 9.9 0 0 0 4.69 1.2h.01c5.46 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2m0 1.8a8.09 8.09 0 0 1 8.1 8.1c0 4.47-3.63 8.1-8.1 8.1a8 8 0 0 1-4.09-1.12l-.29-.17-3.17.83.85-3.1-.19-.3a8.03 8.03 0 0 1-1.24-4.24c0-4.47 3.64-8.1 8.13-8.1m4.49 5.79c-.14-.25-.51-.4-1.07-.67-.55-.27-3.28-1.62-3.79-1.8-.51-.18-.88-.27-1.25.27-.37.55-1.43 1.8-1.75 2.17-.32.37-.65.4-1.2.13-.55-.27-2.32-.86-4.42-2.73-1.63-1.46-2.74-3.27-3.06-3.82-.32-.55-.03-.85.24-1.12.25-.25.55-.65.83-.97.28-.32.37-.55.55-.92.18-.37.09-.68-.05-.96-.14-.27-1.25-3.02-1.71-4.14-.45-1.08-.91-.94-1.25-.96-.32-.02-.7-.02-1.06-.02s-.96.13-1.47.68C1.34 5.7.75 6.86.75 9.1c0 2.24 1.62 4.4 1.85 4.7.23.32 3.19 4.87 7.73 6.83 1.08.47 1.92.75 2.58.96 1.08.34 2.07.29 2.85.18.87-.13 2.68-1.1 3.06-2.16.37-1.06.37-1.97.26-2.16" />
            </svg>
          </span>
          <div>
            <p class="font-semibold">WhatsApp / Teléfono</p>
            <p class="text-muted-foreground text-sm">461 358 1090</p>
          </div>
        </a>

        <a
          href="mailto:ventas@prosello.com.mx"
          class="border-border hover:border-primary flex items-center gap-4 rounded-xl border bg-white p-4 transition"
        >
          <span class="bg-primary/10 text-primary flex h-11 w-11 shrink-0 items-center justify-center rounded-full">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z" />
            </svg>
          </span>
          <div>
            <p class="font-semibold">Correo</p>
            <p class="text-muted-foreground text-sm">ventas@prosello.com.mx</p>
          </div>
        </a>

        <div class="border-border flex items-center gap-4 rounded-xl border bg-white p-4">
          <span class="bg-primary/10 text-primary flex h-11 w-11 shrink-0 items-center justify-center rounded-full">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17.66 17.66A8 8 0 1 0 6.34 6.34a8 8 0 0 0 11.32 11.32zM12 21s-6-5.5-6-10a6 6 0 1 1 12 0c0 4.5-6 10-6 10z" />
              <circle cx="12" cy="11" r="2.5" stroke-width="1.8" />
            </svg>
          </span>
          <div>
            <p class="font-semibold">Ubicación</p>
            <p class="text-muted-foreground text-sm">Celaya, Gto.</p>
          </div>
        </div>
      </div>

      <ContactoForm />
    </div>
  </section>
</template>
