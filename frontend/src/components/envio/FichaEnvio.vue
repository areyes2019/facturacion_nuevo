<script setup lang="ts">
/**
 * Ficha de un envío ya creado, compartida entre la Orden de Trabajo (038) y el envío directo de
 * Cotización de distribuidor (041-envio-domicilio-direccion-y-distribuidor.md).
 *
 * `onMarcarEntregado` solo se pasa desde el envío directo: el de Orden de Trabajo se marca
 * entregado desde la orden misma (038), no desde aquí.
 */
import { computed, ref } from 'vue'
import { ShareIcon } from '@heroicons/vue/24/outline'
import { Card, CardContent, CardHeader, CardTitle } from '../ui/card'
import { Button } from '../ui/button'
import { compartirTexto } from '../../lib/compartir'
import type { Envio } from '../../stores/ordenesTrabajo'

const props = defineProps<{
  envio: Envio
  /** Líneas de contexto propias de cada documento (cliente, ticket, número de orden/cotización...). */
  lineas: string[]
  importePendiente: number
  onMarcarEntregado?: () => Promise<void>
}>()

const compartiendo = ref(false)
const marcando = ref(false)
const error = ref<string | null>(null)

const texto = computed(() =>
  [
    'ENVÍO',
    '',
    ...props.lineas,
    `Nombre de quien recibe: ${props.envio.nombre_receptor}`,
    `Teléfono de quien recibe: ${props.envio.telefono_receptor}`,
    `Dirección: ${props.envio.direccion ?? '(sin capturar)'}`,
    `Importe pendiente: $${props.importePendiente.toFixed(2)}`,
    `Estado del pago: ${props.envio.forma_pago === 'prepagado' ? 'Prepagado' : 'Por cobrar'}`,
    `Fecha: ${props.envio.fecha_recepcion}`,
    `Hora: ${props.envio.hora_recepcion}`,
  ].join('\n'),
)

async function compartir() {
  compartiendo.value = true
  try {
    await compartirTexto(texto.value)
  } catch {
    error.value = 'No se pudo compartir la ficha.'
  } finally {
    compartiendo.value = false
  }
}

async function marcarEntregado() {
  if (!props.onMarcarEntregado) return
  marcando.value = true
  error.value = null
  try {
    await props.onMarcarEntregado()
  } catch {
    error.value = 'No se pudo marcar como entregado.'
  } finally {
    marcando.value = false
  }
}
</script>

<template>
  <Card>
    <CardHeader class="flex flex-row items-center justify-between">
      <CardTitle class="text-base">Ficha de envío</CardTitle>
      <Button size="sm" :disabled="compartiendo" @click="compartir">
        <ShareIcon class="size-4" />
        Compartir
      </Button>
    </CardHeader>
    <CardContent class="space-y-3">
      <pre class="text-sm whitespace-pre-wrap">{{ texto }}</pre>
      <p v-if="error" class="text-destructive text-sm">{{ error }}</p>
      <template v-if="onMarcarEntregado">
        <Button
          v-if="!envio.entregado_en"
          size="sm"
          variant="outline"
          :disabled="marcando"
          @click="marcarEntregado"
        >
          {{ marcando ? 'Marcando...' : 'Marcar entregado' }}
        </Button>
        <p v-else class="text-muted-foreground text-sm">
          Entregado el {{ new Date(envio.entregado_en).toLocaleString() }}
        </p>
      </template>
    </CardContent>
  </Card>
</template>
