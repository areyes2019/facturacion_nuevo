<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useEmisorStore } from '../stores/emisor'
import { Alert, AlertDescription } from './ui/alert'

/**
 * Aviso junto al botón de descargar PDF cuando faltan los datos fiscales del emisor
 * (ver specs/019-formato-pdf-documentos.md).
 *
 * El documento se genera igual —nunca se bloquea a nadie por una configuración incompleta—, así
 * que sin este aviso saldría sin encabezado y el error se descubriría cuando ya lo recibió el
 * cliente.
 */
const emisor = useEmisorStore()

onMounted(() => {
  // Una sola consulta por sesión, compartida por las tres pantallas de detalle.
  void emisor.fetchUnaVez()
})
</script>

<template>
  <Alert v-if="emisor.incompleto" variant="destructive">
    <AlertDescription>
      Este documento se imprimirá sin tus datos fiscales.
      <RouterLink to="/configuracion" class="underline">Captúralos en Configuración</RouterLink>.
    </AlertDescription>
  </Alert>
</template>
