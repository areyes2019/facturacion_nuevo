<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useConfiguracionStore, type ConfiguracionPayload } from '../stores/configuracion'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { TAMANOS_GOMA } from '../lib/tamanoGoma'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../components/ui/dialog'
import AppLayout from '../layouts/AppLayout.vue'
import EmisorForm from '../components/EmisorForm.vue'
import DatosBancariosForm from '../components/DatosBancariosForm.vue'
import MensajePedidoForm from '../components/MensajePedidoForm.vue'
import TarifasEnvioForm from '../components/TarifasEnvioForm.vue'

/**
 * Ajustes globales del sistema (ver specs/014-costo-elaboracion-goma.md).
 *
 * Es una pantalla de secciones, no la pantalla de una sola cosa: hoy solo tiene "Costos de
 * elaboración", y la tasa de IVA, los datos fiscales del emisor y los folios entrarán como
 * secciones hermanas sin rehacerla ni mover la URL.
 */
const configuracion = useConfiguracionStore()

/** Valor capturado de cada costo, como texto, indexado por clave de configuración. */
const form = reactive<Record<string, string>>({})

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})
const guardado = ref(false)

/** Diálogo de confirmación: cuántos artículos cambiarían de precio al guardar. */
const confirmacionAbierta = ref(false)
const articulosAfectados = ref(0)

onMounted(async () => {
  cargando.value = true
  try {
    const valores = await configuracion.fetch()
    for (const tamano of TAMANOS_GOMA) {
      form[tamano.clave] = valores[tamano.clave]
    }
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

/** Solo las claves que el usuario efectivamente cambió. */
const cambios = computed<ConfiguracionPayload>(() => {
  const payload: ConfiguracionPayload = {}

  for (const tamano of TAMANOS_GOMA) {
    const vigente = configuracion.valores?.[tamano.clave]
    if (vigente !== undefined && Number(form[tamano.clave]) !== Number(vigente)) {
      payload[tamano.clave] = form[tamano.clave]
    }
  }

  return payload
})

const hayCambios = computed(() => Object.keys(cambios.value).length > 0)

/**
 * Antes de mover precios se consulta el impacto exacto. Si nada cambiaría, se guarda sin
 * preguntar: un diálogo que dice "se recalcularán 0 artículos" solo estorba.
 */
async function onSubmit() {
  if (!hayCambios.value) {
    return
  }

  errorGeneral.value = null
  erroresPorCampo.value = {}
  guardado.value = false

  try {
    articulosAfectados.value = await configuracion.impactoPrecios(cambios.value)
  } catch (err) {
    erroresPorCampo.value = extractFieldErrors(err)
    errorGeneral.value = extractErrorMessage(err)
    return
  }

  if (articulosAfectados.value === 0) {
    await guardar()
    return
  }

  confirmacionAbierta.value = true
}

async function guardar() {
  guardando.value = true
  errorGeneral.value = null
  erroresPorCampo.value = {}

  try {
    const valores = await configuracion.update(cambios.value)
    for (const tamano of TAMANOS_GOMA) {
      form[tamano.clave] = valores[tamano.clave]
    }
    guardado.value = true
  } catch (err) {
    erroresPorCampo.value = extractFieldErrors(err)
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
    confirmacionAbierta.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-2xl space-y-4">
      <h1 class="font-heading text-foreground text-xl font-semibold">Configuración</h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <Alert v-if="guardado">
        <AlertDescription>Los ajustes se guardaron.</AlertDescription>
      </Alert>

      <!-- Sección hermana con su propio formulario y su propio botón: guardar el emisor no puede
           arrastrar el recálculo de precios de los costos de goma (ver specs/019). -->
      <EmisorForm />

      <!-- Otra sección hermana, con su propio guardado por la misma razón (ver specs/026). -->
      <DatosBancariosForm />

      <!-- Y los dos textos que se le mandan al cliente de mostrador, del mismo modo (specs/027). -->
      <MensajePedidoForm
        clave="mensaje_ticket"
        titulo="Mensaje del ticket"
        descripcion="Este texto se comparte junto con la imagen del ticket de mostrador. Puedes dejarlo vacío si prefieres mandar solo la imagen."
      />

      <MensajePedidoForm
        clave="mensaje_listo"
        titulo="Mensaje de pedido listo"
        descripcion="El aviso que se manda con el botón «Avisar que está listo» del pedido. Lo disparas tú cuando el trabajo lo está: el sello se hace fuera del sistema y el sistema no puede saberlo."
        :renglones="5"
      />

      <!-- Producción (ver 038-produccion-ordenes-trabajo.md): mismo criterio de sección hermana. -->
      <TarifasEnvioForm />

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Costos de elaboración</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <p class="text-muted-foreground text-sm">
              Lo que te cuesta elaborar la goma de cada tamaño. Se suma al costo del aparato en los
              artículos que llevan goma.
            </p>

            <div v-for="tamano in TAMANOS_GOMA" :key="tamano.valor" class="space-y-1.5">
              <Label :for="tamano.clave">{{ tamano.etiqueta }}</Label>
              <div class="flex items-center gap-2">
                <span class="text-muted-foreground text-sm">$</span>
                <Input
                  :id="tamano.clave"
                  v-model="form[tamano.clave]"
                  type="number"
                  min="0"
                  step="0.01"
                  class="max-w-40"
                />
              </div>
              <!-- Las medidas son orientativas: el sistema no captura dimensiones ni deduce la
                   categoría. La elige el usuario a ojo. -->
              <p class="text-muted-foreground text-sm">{{ tamano.medida }}</p>
              <p v-if="erroresPorCampo[tamano.clave]" class="text-destructive text-sm">
                {{ erroresPorCampo[tamano.clave] }}
              </p>
            </div>
          </CardContent>
        </Card>

        <div class="flex justify-end">
          <Button type="submit" :disabled="guardando || !hayCambios">
            {{ guardando ? 'Guardando...' : 'Guardar' }}
          </Button>
        </div>
      </form>
    </div>

    <Dialog v-model:open="confirmacionAbierta">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Se van a recalcular precios</DialogTitle>
          <DialogDescription>
            Se recalculará el precio de venta de
            {{ articulosAfectados }}
            {{ articulosAfectados === 1 ? 'artículo' : 'artículos' }}. El costo del proveedor y el
            porcentaje de utilidad no se tocan.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="confirmacionAbierta = false">Cancelar</Button>
          <Button :disabled="guardando" @click="guardar">
            {{ guardando ? 'Guardando...' : 'Guardar y recalcular' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
