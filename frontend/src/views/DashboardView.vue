<script setup lang="ts">
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import { enModoMostrador } from '../lib/modoMostrador'
import { instalar, puedeInstalar } from '../lib/instalacion'
import AppLayout from '../layouts/AppLayout.vue'
import CuatroAccesos from '../components/mostrador/CuatroAccesos.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent } from '../components/ui/card'

/**
 * Una sola dirección de inicio que muestra dos cosas distintas (ver 029-pwa-mostrador.md): abierta
 * desde el icono instalado, los cuatro accesos del mostrador; abierta en el navegador, la pantalla
 * de inicio de siempre. Sin una segunda dirección que aprender.
 */
const mostrador = enModoMostrador()
</script>

<template>
  <AppLayout :mostrador="mostrador">
    <CuatroAccesos v-if="mostrador" />

    <div v-else class="space-y-4">
      <Card>
        <CardContent class="py-16 text-center">
          <p class="text-foreground text-lg">pagina de inicio</p>
        </CardContent>
      </Card>

      <!-- Instalar depende hoy de encontrar "Instalar" en el menú del navegador, que casi nadie
           encuentra. El botón desaparece cuando ya está instalada o cuando el navegador no la
           ofrece: uno que no puede cumplir lo que promete es peor que ninguno. -->
      <Card v-if="puedeInstalar">
        <CardContent class="flex flex-wrap items-center justify-between gap-3 py-4">
          <p class="text-muted-foreground text-sm">
            Instala la aplicación en el celular del mostrador para vender con cuatro botones.
          </p>
          <Button @click="instalar">
            <ArrowDownTrayIcon class="size-4" />
            Instalar aplicación
          </Button>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
