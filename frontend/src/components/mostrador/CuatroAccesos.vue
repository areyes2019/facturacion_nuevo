<script setup lang="ts">
import { useRouter } from 'vue-router'
import {
  DocumentDuplicateIcon,
  DocumentTextIcon,
  QrCodeIcon,
  TicketIcon,
} from '@heroicons/vue/24/outline'
import { useAuthStore } from '../../stores/auth'
import { iconoCerrarSesion } from '../../config/navegacion'

/**
 * La pantalla de inicio del modo mostrador (ver 029-pwa-mostrador.md).
 *
 * Cuatro botones y nada más. **Sin cifras, sin gráficas y sin pendientes**: un número en esta
 * pantalla invita a interpretarlo, y esta pantalla no es para leer, es para arrancar a trabajar en
 * un toque. **Los cuatro son fijos**: no se reordenan, no se configuran y no cambian según lo que
 * más se use, porque un botón que se mueve solo obliga a mirar antes de tocar.
 *
 * Los íconos son los mismos con que cada módulo aparece en el menú de 013, para que quien use las
 * dos caras del sistema reconozca el mismo dibujo en las dos.
 */

const ACCESOS = [
  { name: 'mostrador-factura', etiqueta: 'Generar factura', icono: DocumentTextIcon },
  { name: 'mostrador-cotizacion', etiqueta: 'Generar cotización', icono: DocumentDuplicateIcon },
  { name: 'mostrador-venta', etiqueta: 'Venta al público', icono: TicketIcon },
  { name: 'mostrador-escanear', etiqueta: 'Escanear etiquetas', icono: QrCodeIcon },
]

const router = useRouter()
const auth = useAuthStore()

async function onLogout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <!-- El alto descontado es el de la barra de arriba, el respiro del `main` y el de la barra de
       secciones del pie (ver 031-mostrador-consulta.md). -->
  <div class="flex min-h-[calc(100svh-15rem)] flex-col gap-3">
    <!-- Cuadrícula de dos por dos que reparte el alto en partes iguales. Toda la superficie del
         cuadro es tocable, no solo las letras. -->
    <div class="grid flex-1 grid-cols-2 grid-rows-2 gap-3">
      <RouterLink
        v-for="acceso in ACCESOS"
        :key="acceso.name"
        :to="{ name: acceso.name }"
        class="border-border bg-background text-foreground hover:bg-accent focus-visible:ring-ring flex flex-col items-center justify-center gap-3 rounded-xl border p-3 text-center transition-colors focus-visible:ring-3 focus-visible:outline-none"
      >
        <component :is="acceso.icono" class="text-primary size-12" />
        <span class="text-base leading-tight font-medium">{{ acceso.etiqueta }}</span>
      </RouterLink>
    </div>

    <!-- Tiene que existir en algún lado —un aparato del que no se puede salir es un aparato
         prestado para siempre— pero no compite con los cuatro botones. -->
    <button
      type="button"
      class="text-muted-foreground hover:text-foreground mx-auto flex items-center gap-2 py-2 text-sm"
      @click="onLogout"
    >
      <component :is="iconoCerrarSesion" class="size-4" />
      Cerrar sesión
    </button>
  </div>
</template>
