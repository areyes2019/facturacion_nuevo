<script setup lang="ts">
import { ArrowPathIcon } from '@heroicons/vue/24/outline'
import { Button } from './ui/button'
import { aplicarActualizacion, hayVersionNueva } from '../lib/actualizacion'

/**
 * Aviso de versión nueva (ver 029-pwa-mostrador.md).
 *
 * Antes la actualización era silenciosa, lo que en la práctica significaba que una pestaña abierta
 * desde ayer seguía con la versión vieja hasta que alguien la cerrara, sin que nadie lo supiera. Un
 * aparato de mostrador se queda abierto días enteros: es justo donde ese silencio dura más.
 *
 * El aviso **no interrumpe** —se puede seguir vendiendo con la versión que ya está cargada— pero
 * deja de ser un secreto.
 *
 * El registro del service worker vive en `lib/actualizacion.ts` y se comparte con el manejador de
 * chunks faltantes de `main.ts` (ver 044-sesion-caida-y-pantallas-trabadas.md).
 */
</script>

<template>
  <div
    v-if="hayVersionNueva"
    class="border-border bg-background fixed inset-x-0 bottom-0 z-50 flex flex-wrap items-center justify-center gap-3 border-t px-4 py-3 shadow-lg"
  >
    <p class="text-sm">Hay una versión nueva del sistema.</p>
    <Button size="sm" @click="aplicarActualizacion()">
      <ArrowPathIcon class="size-4" />
      Recargar
    </Button>
    <Button size="sm" variant="ghost" @click="hayVersionNueva = false">Ahora no</Button>
  </div>
</template>
