<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftIcon, ShareIcon } from '@heroicons/vue/24/outline'
import {
  useCotizacionesStore,
  type Cotizacion,
  type CotizacionPayload,
} from '../../stores/cotizaciones'
import { calcularTotales } from '../../lib/totalesDocumento'
import { mensajeDeFalla } from '../../lib/errors'
import { useConfirmarSalida } from '../../lib/salidaCaptura'
import AppLayout from '../../layouts/AppLayout.vue'
import PasosMostrador from '../../components/mostrador/PasosMostrador.vue'
import PasoArticulos from '../../components/mostrador/PasoArticulos.vue'
import PasoClienteFiscal from '../../components/mostrador/PasoClienteFiscal.vue'
import type { LineaEditable } from '../../components/DocumentoLineas.vue'
import type { ClienteResultado } from '../../components/ClienteCombobox.vue'
import { Alert, AlertDescription } from '../../components/ui/alert'
import { Button } from '../../components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'

/**
 * Cotización capturada por pasos (ver 029-pwa-mostrador.md).
 *
 * El backend exige `cliente_id` del catálogo fiscal, así que al cliente que no está se le da de
 * alta escaneando su constancia, igual que en la factura.
 */

const PASOS = ['Cliente', 'Artículos', 'Resumen', 'Listo']

const router = useRouter()
const cotizaciones = useCotizacionesStore()

const paso = ref(0)

const cliente = ref<ClienteResultado | null>(null)
const lineas = ref<LineaEditable[]>([])

const cotizacion = ref<Cotizacion | null>(null)
const guardando = ref(false)
const error = ref<string | null>(null)

const telefono = ref('')
const enviando = ref(false)
const avisoEnvio = ref<string | null>(null)

const totales = computed(() => calcularTotales(lineas.value, null, null))

const hayCaptura = computed(
  () => paso.value < 3 && (cliente.value !== null || lineas.value.length > 0),
)

const { confirmandoSalida, confirmarSalida, cancelarSalida } = useConfirmarSalida(
  () => hayCaptura.value,
)

function puedeAvanzar(): boolean {
  return paso.value === 0 ? cliente.value !== null : lineas.value.length > 0
}

async function guardar() {
  guardando.value = true
  error.value = null

  const payload: CotizacionPayload = {
    cliente_id: cliente.value?.id ?? null,
    descuento_global_tipo: null,
    descuento_global_valor: null,
    lineas: lineas.value,
    total: totales.value.total,
  }

  try {
    cotizacion.value = await cotizaciones.create(payload)
    telefono.value = cotizacion.value.cliente_telefono ?? ''
    paso.value = 3
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    guardando.value = false
  }
}

/** El PDF sale del servidor y Twilio lo descarga del enlace público (ver 008-cotizaciones.md). */
async function enviarPorWhatsapp() {
  if (cotizacion.value === null || telefono.value.trim() === '') return

  enviando.value = true
  avisoEnvio.value = null

  try {
    await cotizaciones.enviar(cotizacion.value.id, {
      canal: 'whatsapp',
      telefono: telefono.value.trim(),
    })
    avisoEnvio.value = 'Cotización enviada.'
  } catch (err) {
    avisoEnvio.value = mensajeDeFalla(err)
  } finally {
    enviando.value = false
  }
}

function nuevaCotizacion() {
  cliente.value = null
  lineas.value = []
  cotizacion.value = null
  error.value = null
  telefono.value = ''
  avisoEnvio.value = null
  paso.value = 0
}
</script>

<template>
  <AppLayout mostrador>
    <div class="mx-auto max-w-md space-y-5">
      <h1 class="font-heading text-foreground text-xl font-semibold">Generar cotización</h1>

      <PasosMostrador :pasos="PASOS" :actual="paso" />

      <PasoClienteFiscal v-if="paso === 0" v-model="cliente" />

      <PasoArticulos
        v-else-if="paso === 1"
        v-model:lineas="lineas"
        :descuento-porcentaje="cliente?.descuento_permanente ?? 0"
      />

      <div v-else-if="paso === 2" class="space-y-6">
        <div class="space-y-1 text-center">
          <p class="text-foreground text-2xl font-semibold">{{ cliente?.razon_social }}</p>
          <p class="text-muted-foreground font-mono text-lg">{{ cliente?.rfc }}</p>
        </div>

        <p class="text-center text-4xl font-semibold">${{ totales.total.toFixed(2) }}</p>

        <Alert v-if="error" variant="destructive">
          <AlertDescription>{{ error }}</AlertDescription>
        </Alert>
      </div>

      <div v-else class="space-y-4">
        <p class="text-center">
          Cotización
          <strong class="font-mono">{{ cotizacion?.folio }}</strong> guardada.
        </p>

        <div class="space-y-1.5">
          <Label for="telefono-cotizacion">Teléfono del cliente</Label>
          <Input
            id="telefono-cotizacion"
            v-model="telefono"
            inputmode="tel"
            class="h-12 text-base"
          />
        </div>

        <Alert v-if="avisoEnvio">
          <AlertDescription>{{ avisoEnvio }}</AlertDescription>
        </Alert>

        <Button
          class="h-14 w-full text-base"
          :disabled="enviando || telefono.trim() === ''"
          @click="enviarPorWhatsapp"
        >
          <ShareIcon class="size-5" />
          {{ enviando ? 'Enviando...' : 'Enviar por WhatsApp' }}
        </Button>

        <div class="flex gap-2">
          <Button variant="outline" class="h-12 flex-1" @click="nuevaCotizacion">
            Nueva cotización
          </Button>
          <Button variant="ghost" class="h-12 flex-1" @click="router.push({ name: 'dashboard' })">
            Inicio
          </Button>
        </div>
      </div>

      <div v-if="paso < 3" class="flex items-center gap-2 pt-2">
        <Button
          v-if="paso > 0"
          type="button"
          variant="outline"
          size="icon-lg"
          :disabled="guardando"
          @click="paso -= 1"
        >
          <ArrowLeftIcon class="size-5" />
          <span class="sr-only">Paso anterior</span>
        </Button>

        <Button
          v-if="paso < 2"
          type="button"
          class="h-14 flex-1 text-base"
          :disabled="!puedeAvanzar()"
          @click="paso += 1"
        >
          Siguiente
        </Button>

        <Button
          v-else
          type="button"
          class="h-14 flex-1 text-base"
          :disabled="guardando"
          @click="guardar"
        >
          {{ guardando ? 'Guardando...' : 'Guardar cotización' }}
        </Button>
      </div>
    </div>

    <Dialog :open="confirmandoSalida" @update:open="(v) => !v && cancelarSalida()">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>¿Salir de la cotización?</DialogTitle>
          <DialogDescription>
            Lo que llevas capturado se pierde. La cotización todavía no está guardada.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="cancelarSalida">Seguir capturando</Button>
          <Button variant="destructive" @click="confirmarSalida">Salir</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
