<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useConfiguracionStore, type Configuracion } from '../stores/configuracion'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from './ui/button'
import { Card, CardContent, CardHeader, CardTitle } from './ui/card'
import { Alert, AlertDescription } from './ui/alert'

/**
 * Uno de los dos textos que se le mandan al cliente de mostrador (ver
 * 027-venta-mostrador-ticket.md): el que acompaña al ticket y el aviso de "ya está listo".
 *
 * Un solo componente con la clave por propiedad, y no dos casi idénticos: los dos guardan un
 * `textarea` contra el mismo almacén y comparten la misma lista de huecos, así que una copia solo
 * podría divergir la primera vez que se corrija algo.
 *
 * Sección hermana con su propio guardado, igual que `EmisorForm` (019) y `DatosBancariosForm`
 * (026): guardar este texto no puede arrastrar el recálculo de precios de los costos de goma.
 */
const HUECOS = [
  { hueco: '{nombre}', descripcion: 'nombre del cliente' },
  { hueco: '{folio}', descripcion: 'número de ticket' },
  { hueco: '{total}', descripcion: 'total de la compra' },
  { hueco: '{pagado}', descripcion: 'lo que lleva pagado' },
  { hueco: '{saldo}', descripcion: 'saldo pendiente' },
]

const props = withDefaults(
  defineProps<{
    clave: Extract<keyof Configuracion, 'mensaje_ticket' | 'mensaje_listo'>
    titulo: string
    descripcion: string
    renglones?: number
  }>(),
  { renglones: 12 },
)

const configuracion = useConfiguracionStore()

const mensaje = ref('')
const original = ref('')
const cargando = ref(true)
const guardando = ref(false)
const guardado = ref(false)
const error = ref<string | null>(null)
const errorCampo = ref<string | null>(null)

const hayCambios = computed(() => mensaje.value !== original.value)

onMounted(async () => {
  try {
    const valores = await configuracion.fetch()
    mensaje.value = valores[props.clave] ?? ''
    original.value = mensaje.value
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

async function guardar() {
  guardando.value = true
  error.value = null
  errorCampo.value = null
  guardado.value = false

  try {
    const valores = await configuracion.update({ [props.clave]: mensaje.value })
    original.value = valores[props.clave] ?? ''
    guardado.value = true
  } catch (err) {
    errorCampo.value = extractFieldErrors(err)[props.clave] ?? null
    error.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="text-base">{{ titulo }}</CardTitle>
    </CardHeader>
    <CardContent class="space-y-4">
      <p class="text-muted-foreground text-sm">{{ descripcion }}</p>

      <textarea
        v-model="mensaje"
        :rows="renglones"
        :disabled="cargando"
        class="border-input focus-visible:ring-ring w-full rounded-md border bg-transparent p-3 text-sm focus-visible:ring-1 focus-visible:outline-none"
      />

      <div class="text-muted-foreground space-y-1 text-sm">
        <p>El sistema rellena estos huecos al compartir:</p>
        <ul class="space-y-0.5">
          <li v-for="item in HUECOS" :key="item.hueco">
            <code class="bg-muted rounded px-1 py-0.5">{{ item.hueco }}</code>
            — {{ item.descripcion }}
          </li>
        </ul>
      </div>

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ errorCampo ?? error }}</AlertDescription>
      </Alert>
      <Alert v-if="guardado">
        <AlertDescription>El mensaje se guardó.</AlertDescription>
      </Alert>

      <div class="flex justify-end">
        <Button :disabled="guardando || !hayCambios" @click="guardar">
          {{ guardando ? 'Guardando...' : 'Guardar mensaje' }}
        </Button>
      </div>
    </CardContent>
  </Card>
</template>
