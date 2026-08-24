<script setup lang="ts">
import { reactive, ref } from 'vue'
import { API_CONTACTO_URL } from '@/lib/config'
import { enlaceWhatsapp } from '@/lib/whatsapp'

const form = reactive({
  nombre: '',
  correo: '',
  telefono: '',
  mensaje: '',
  // Honeypot: invisible para una persona (ver estilos en el input), un bot de envíos
  // automáticos sí lo llena.
  empresa_web: '',
})

type Estado = 'inicial' | 'enviando' | 'enviado' | 'error'
const estado = ref<Estado>('inicial')

async function enviar() {
  estado.value = 'enviando'

  try {
    const respuesta = await fetch(API_CONTACTO_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    })

    if (!respuesta.ok) {
      throw new Error(`Respuesta ${respuesta.status}`)
    }

    estado.value = 'enviado'
  } catch {
    estado.value = 'error'
  }
}
</script>

<template>
  <div class="border-border rounded-2xl border bg-white p-6 sm:p-8">
    <div v-if="estado === 'enviado'" class="py-8 text-center">
      <h3 class="font-heading text-xl font-bold">Gracias, te contactaremos pronto.</h3>
      <p class="text-muted-foreground mt-2">
        ¿Prefieres una respuesta más rápida?
      </p>
      <a
        :href="enlaceWhatsapp('general')"
        target="_blank"
        rel="noopener"
        class="bg-whatsapp text-whatsapp-foreground mt-4 inline-flex items-center justify-center rounded-full px-6 py-3 text-sm font-semibold shadow-sm transition hover:brightness-95"
      >
        Habla por WhatsApp
      </a>
    </div>

    <form v-else class="space-y-4" @submit.prevent="enviar">
      <h3 class="font-heading text-xl font-bold">Escríbenos</h3>

      <div>
        <label for="nombre" class="mb-1 block text-sm font-medium">Nombre</label>
        <input
          id="nombre"
          v-model="form.nombre"
          type="text"
          required
          class="border-border focus:border-primary focus:ring-primary w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1"
        />
      </div>

      <div>
        <label for="correo" class="mb-1 block text-sm font-medium">Correo</label>
        <input
          id="correo"
          v-model="form.correo"
          type="email"
          required
          class="border-border focus:border-primary focus:ring-primary w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1"
        />
      </div>

      <div>
        <label for="telefono" class="mb-1 block text-sm font-medium">Teléfono</label>
        <input
          id="telefono"
          v-model="form.telefono"
          type="tel"
          required
          class="border-border focus:border-primary focus:ring-primary w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1"
        />
      </div>

      <div>
        <label for="mensaje" class="mb-1 block text-sm font-medium">Mensaje</label>
        <textarea
          id="mensaje"
          v-model="form.mensaje"
          rows="4"
          required
          class="border-border focus:border-primary focus:ring-primary w-full rounded-lg border px-3 py-2 text-sm outline-none focus:ring-1"
        />
      </div>

      <!-- Honeypot: oculto visualmente sin usar left negativo, que ensancha la página entera en
           móvil (ver captura de la revisión). -->
      <div class="absolute h-px w-px overflow-hidden" style="clip: rect(0, 0, 0, 0)" aria-hidden="true">
        <label for="empresa_web">No llenar este campo</label>
        <input id="empresa_web" v-model="form.empresa_web" type="text" tabindex="-1" autocomplete="off" />
      </div>

      <p v-if="estado === 'error'" class="text-sm text-red-600">
        No se pudo enviar. Intenta de nuevo o escríbenos por WhatsApp.
      </p>

      <button
        type="submit"
        :disabled="estado === 'enviando'"
        class="bg-primary w-full rounded-full px-6 py-3 text-base font-semibold text-white shadow-sm transition hover:brightness-110 disabled:opacity-60"
      >
        {{ estado === 'enviando' ? 'Enviando…' : 'Enviar mensaje' }}
      </button>
    </form>
  </div>
</template>
