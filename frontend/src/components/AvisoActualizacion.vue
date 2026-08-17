<script setup lang="ts">
import { useRegisterSW } from 'virtual:pwa-register/vue'
import { ArrowPathIcon } from '@heroicons/vue/24/outline'
import { Button } from './ui/button'

/**
 * Aviso de versión nueva (ver 029-pwa-mostrador.md).
 *
 * Antes la actualización era silenciosa, lo que en la práctica significaba que una pestaña abierta
 * desde ayer seguía con la versión vieja hasta que alguien la cerrara, sin que nadie lo supiera. Un
 * aparato de mostrador se queda abierto días enteros: es justo donde ese silencio dura más.
 *
 * El aviso **no interrumpe** —se puede seguir vendiendo con la versión que ya está cargada— pero
 * deja de ser un secreto.
 */
const { needRefresh, updateServiceWorker } = useRegisterSW()
</script>

<template>
  <div
    v-if="needRefresh"
    class="border-border bg-background fixed inset-x-0 bottom-0 z-50 flex flex-wrap items-center justify-center gap-3 border-t px-4 py-3 shadow-lg"
  >
    <p class="text-sm">Hay una versión nueva del sistema.</p>
    <Button size="sm" @click="updateServiceWorker(true)">
      <ArrowPathIcon class="size-4" />
      Recargar
    </Button>
    <Button size="sm" variant="ghost" @click="needRefresh = false">Ahora no</Button>
  </div>
</template>
