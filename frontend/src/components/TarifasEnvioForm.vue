<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useConfiguracionStore } from '../stores/configuracion'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from './ui/button'
import { Card, CardContent, CardHeader, CardTitle } from './ui/card'
import { Input } from './ui/input'
import { Label } from './ui/label'
import { Alert, AlertDescription } from './ui/alert'

/**
 * Tarifas fijas de envío a domicilio (ver 038-produccion-ordenes-trabajo.md), mismo mecanismo que
 * los costos de goma de 014: se eligen a mano en el formulario de envío, aquí solo se edita el
 * monto de cada una.
 *
 * Sección hermana con su propio guardado, igual que `MensajePedidoForm`: guardar una tarifa no
 * tiene nada que ver con el recálculo de precios de artículos, así que no pasa por ese diálogo.
 */
const CLAVES = [
  { clave: 'envio_tarifa_a', etiqueta: 'Tarifa A' },
  { clave: 'envio_tarifa_b', etiqueta: 'Tarifa B' },
  { clave: 'envio_tarifa_c', etiqueta: 'Tarifa C' },
] as const

const configuracion = useConfiguracionStore()

const form = reactive<Record<string, string>>({})
const original = reactive<Record<string, string>>({})
const cargando = ref(true)
const guardando = ref(false)
const guardado = ref(false)
const error = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

const hayCambios = computed(() => CLAVES.some((c) => form[c.clave] !== original[c.clave]))

onMounted(async () => {
  try {
    const valores = await configuracion.fetch()
    for (const { clave } of CLAVES) {
      form[clave] = valores[clave]
      original[clave] = valores[clave]
    }
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

async function guardar() {
  guardando.value = true
  error.value = null
  erroresPorCampo.value = {}
  guardado.value = false

  const payload: Record<string, string> = {}
  for (const { clave } of CLAVES) {
    if (form[clave] !== original[clave]) payload[clave] = form[clave]
  }

  try {
    const valores = await configuracion.update(payload)
    for (const { clave } of CLAVES) {
      form[clave] = valores[clave]
      original[clave] = valores[clave]
    }
    guardado.value = true
  } catch (err) {
    erroresPorCampo.value = extractFieldErrors(err)
    error.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="text-base">Tarifas de envío a domicilio</CardTitle>
    </CardHeader>
    <CardContent class="space-y-4">
      <p class="text-muted-foreground text-sm">
        El monto de cada tarifa. En el formulario de envío eliges a mano cuál aplica según la zona
        del cliente.
      </p>

      <div v-for="item in CLAVES" :key="item.clave" class="space-y-1.5">
        <Label :for="item.clave">{{ item.etiqueta }}</Label>
        <div class="flex items-center gap-2">
          <span class="text-muted-foreground text-sm">$</span>
          <Input
            :id="item.clave"
            v-model="form[item.clave]"
            type="number"
            min="0"
            step="0.01"
            class="max-w-40"
            :disabled="cargando"
          />
        </div>
        <p v-if="erroresPorCampo[item.clave]" class="text-destructive text-sm">
          {{ erroresPorCampo[item.clave] }}
        </p>
      </div>

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>
      <Alert v-if="guardado">
        <AlertDescription>Las tarifas se guardaron.</AlertDescription>
      </Alert>

      <div class="flex justify-end">
        <Button :disabled="guardando || !hayCambios" @click="guardar">
          {{ guardando ? 'Guardando...' : 'Guardar tarifas' }}
        </Button>
      </div>
    </CardContent>
  </Card>
</template>
