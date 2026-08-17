<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftIcon, EnvelopeIcon, ShareIcon } from '@heroicons/vue/24/outline'
import {
  useCotizacionesStore,
  type Cotizacion,
  type CotizacionPayload,
} from '../../stores/cotizaciones'
import { calcularTotales } from '../../lib/totalesDocumento'
import { reaplicarDescuento } from '../../lib/lineasMostrador'
import { mensajeDeFalla } from '../../lib/errors'
import { useConfirmarSalida } from '../../lib/salidaCaptura'
import AppLayout from '../../layouts/AppLayout.vue'
import PasosMostrador from '../../components/mostrador/PasosMostrador.vue'
import PasoArticulosTarjetas from '../../components/mostrador/PasoArticulosTarjetas.vue'
import PasoClienteTarjetas from '../../components/mostrador/PasoClienteTarjetas.vue'
import CarritoMostrador from '../../components/mostrador/CarritoMostrador.vue'
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
 * Cliente de una lista de tarjetas, artículos del catálogo a un toque, carrito para corregir
 * cantidades y, al final, las dos formas de mandársela al cliente: el menú de compartir del propio
 * aparato con el PDF, y el correo, que sí sale del servidor.
 */

const PASOS = ['Cliente', 'Artículos', 'Carrito', 'Listo']

const router = useRouter()
const cotizaciones = useCotizacionesStore()

const paso = ref(0)

const cliente = ref<ClienteResultado | null>(null)
const lineas = ref<LineaEditable[]>([])

const cotizacion = ref<Cotizacion | null>(null)
const guardando = ref(false)
const error = ref<string | null>(null)

const compartiendo = ref(false)
const avisoEnvio = ref<string | null>(null)

const totales = computed(() => calcularTotales(lineas.value, null, null))

const hayCaptura = computed(
  () => paso.value < 3 && (cliente.value !== null || lineas.value.length > 0),
)

const { confirmandoSalida, confirmarSalida, cancelarSalida } = useConfirmarSalida(
  () => hayCaptura.value,
)

/**
 * Elegir al cliente avanza solo. Si ya había artículos capturados, su descuento se reemplaza por el
 * del cliente nuevo: lo capturado antes se pensó para otro (ver 015).
 */
function onClienteElegido(elegido: ClienteResultado) {
  cliente.value = elegido
  lineas.value = reaplicarDescuento(lineas.value, elegido.descuento_permanente)
  paso.value = 1
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
    correo.value = cotizacion.value.cliente_correo ?? ''
    paso.value = 3
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    guardando.value = false
  }
}

/** El PDF sale del servidor y lo comparte el aparato: Twilio ya no participa (ver 029). */
async function compartirPorWhatsapp() {
  if (cotizacion.value === null) return

  compartiendo.value = true
  avisoEnvio.value = null

  try {
    const resultado = await cotizaciones.compartirPorWhatsapp(cotizacion.value)

    if (resultado === 'descargado') {
      avisoEnvio.value = 'PDF descargado y mensaje copiado: arrástralo a WhatsApp y pega el texto.'
    } else if (resultado === 'compartido') {
      avisoEnvio.value = 'Cotización compartida.'
    }
  } catch (err) {
    avisoEnvio.value = mensajeDeFalla(err)
  } finally {
    compartiendo.value = false
  }
}

// --- Envío por correo, que sí sale del servidor ---

const enviandoCorreo = ref(false)
const dialogoCorreo = ref(false)
const correo = ref('')

async function enviarPorCorreo() {
  if (cotizacion.value === null || correo.value.trim() === '') return

  enviandoCorreo.value = true
  avisoEnvio.value = null

  try {
    await cotizaciones.enviar(cotizacion.value.id, {
      canal: 'correo',
      destinatarios: [correo.value.trim()],
    })
    dialogoCorreo.value = false
    avisoEnvio.value = 'Cotización enviada por correo.'
  } catch (err) {
    avisoEnvio.value = mensajeDeFalla(err)
  } finally {
    enviandoCorreo.value = false
  }
}

function nuevaCotizacion() {
  cliente.value = null
  lineas.value = []
  cotizacion.value = null
  error.value = null
  correo.value = ''
  avisoEnvio.value = null
  paso.value = 0
}
</script>

<template>
  <AppLayout mostrador>
    <div class="mx-auto max-w-md space-y-5">
      <h1 class="font-heading text-foreground text-xl font-semibold">Generar cotización</h1>

      <PasosMostrador :pasos="PASOS" :actual="paso" />

      <PasoClienteTarjetas v-if="paso === 0" @elegido="onClienteElegido" />

      <PasoArticulosTarjetas
        v-else-if="paso === 1"
        v-model:lineas="lineas"
        :descuento-porcentaje="cliente?.descuento_permanente ?? 0"
        etiqueta-terminar="Terminar cotización"
        @terminar="paso = 2"
      />

      <div v-else-if="paso === 2" class="space-y-6">
        <div class="space-y-1 text-center">
          <p class="text-foreground text-xl font-semibold">{{ cliente?.razon_social }}</p>
          <p class="text-muted-foreground font-mono">{{ cliente?.rfc }}</p>
        </div>

        <CarritoMostrador v-model:lineas="lineas" />

        <Alert v-if="error" variant="destructive">
          <AlertDescription>{{ error }}</AlertDescription>
        </Alert>
      </div>

      <div v-else class="space-y-4">
        <div class="space-y-1 text-center">
          <p class="text-muted-foreground">
            Cotización <strong class="text-foreground font-mono">{{ cotizacion?.folio }}</strong>
            guardada
          </p>
          <p class="text-foreground text-lg font-medium">{{ cotizacion?.cliente_razon_social }}</p>
          <p class="text-muted-foreground text-sm">
            {{ cotizacion?.lineas.length }}
            {{ cotizacion?.lineas.length === 1 ? 'renglón' : 'renglones' }}
          </p>
          <p class="text-3xl font-semibold">${{ cotizacion?.total.toFixed(2) }}</p>
        </div>

        <Alert v-if="avisoEnvio">
          <AlertDescription>{{ avisoEnvio }}</AlertDescription>
        </Alert>

        <Button
          class="h-14 w-full text-base"
          :disabled="compartiendo"
          @click="compartirPorWhatsapp"
        >
          <ShareIcon class="size-5" />
          {{ compartiendo ? 'Preparando...' : 'Enviar por WhatsApp' }}
        </Button>

        <Button variant="outline" class="h-14 w-full text-base" @click="dialogoCorreo = true">
          <EnvelopeIcon class="size-5" />
          Enviar por correo
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

      <!-- El paso de cliente y el de artículos se cierran solos —tocar una tarjeta, o el botón del
           pie— así que la barra de abajo solo existe para volver y para guardar. -->
      <div v-if="paso === 1 || paso === 2" class="flex items-center gap-2 pt-2">
        <Button
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
          v-if="paso === 2"
          type="button"
          class="h-14 flex-1 text-base"
          :disabled="guardando || lineas.length === 0"
          @click="guardar"
        >
          {{ guardando ? 'Guardando...' : 'Guardar cotización' }}
        </Button>
      </div>
    </div>

    <Dialog v-model:open="dialogoCorreo">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Enviar por correo</DialogTitle>
          <DialogDescription>
            Sale del servidor con el PDF adjunto. Viene el correo del cliente; puedes cambiarlo.
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-1.5">
          <Label for="correo-cotizacion">Correo</Label>
          <Input id="correo-cotizacion" v-model="correo" inputmode="email" class="h-12 text-base" />
        </div>

        <DialogFooter>
          <Button variant="outline" @click="dialogoCorreo = false">Cancelar</Button>
          <Button :disabled="enviandoCorreo || correo.trim() === ''" @click="enviarPorCorreo">
            {{ enviandoCorreo ? 'Enviando...' : 'Enviar' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

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
