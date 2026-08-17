<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { CubeIcon, DocumentDuplicateIcon, DocumentTextIcon } from '@heroicons/vue/24/outline'

/**
 * La barra de herramientas del mostrador (ver 031-mostrador-consulta.md).
 *
 * Tres secciones para **consultar**, debajo de los cuatro accesos que existen para **crear**. Los
 * íconos de cotización y factura son los mismos con que esos dos documentos aparecen en los cuatro
 * accesos y en el menú de 013, para que el mismo documento se reconozca por el mismo dibujo en las
 * dos caras del sistema.
 *
 * **No hay un cuarto botón de "Inicio"**: se vuelve a los cuatro accesos tocando "Facturación" en
 * la barra de arriba, que es como ya se vuelve desde cualquier pantalla interior del mostrador. Un
 * cuarto botón angostaría los tres por una función que ya tiene su lugar.
 */

const SECCIONES = [
  {
    name: 'mostrador-cotizaciones',
    etiqueta: 'Cotizaciones',
    icono: DocumentDuplicateIcon,
    // El detalle es parte de la sección: entrar a una cotización no apaga su botón.
    rutas: ['mostrador-cotizaciones', 'mostrador-cotizacion-ver'],
  },
  {
    name: 'mostrador-facturas',
    etiqueta: 'Facturas',
    icono: DocumentTextIcon,
    rutas: ['mostrador-facturas', 'mostrador-factura-ver'],
  },
  {
    name: 'mostrador-catalogo',
    etiqueta: 'Catálogo',
    icono: CubeIcon,
    rutas: ['mostrador-catalogo', 'mostrador-articulo-ver'],
  },
]

const route = useRoute()

const nombreRutaActual = computed(() => String(route.name ?? ''))
</script>

<template>
  <nav
    class="bg-background border-border fixed inset-x-0 bottom-0 z-40 border-t"
    aria-label="Secciones del mostrador"
  >
    <div class="mx-auto flex max-w-5xl">
      <RouterLink
        v-for="seccion in SECCIONES"
        :key="seccion.name"
        :to="{ name: seccion.name }"
        class="hover:bg-accent focus-visible:ring-ring flex flex-1 flex-col items-center justify-center gap-1 py-2 focus-visible:ring-2 focus-visible:outline-none focus-visible:-outline-offset-2"
        :class="
          seccion.rutas.includes(nombreRutaActual)
            ? 'text-primary font-medium'
            : 'text-muted-foreground'
        "
        :aria-current="seccion.rutas.includes(nombreRutaActual) ? 'page' : undefined"
      >
        <component :is="seccion.icono" class="size-6" />
        <span class="text-xs leading-none">{{ seccion.etiqueta }}</span>
      </RouterLink>
    </div>
  </nav>
</template>
