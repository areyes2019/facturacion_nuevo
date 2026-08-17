<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftIcon, EnvelopeIcon } from '@heroicons/vue/24/outline'
import { useFacturasStore, type Factura, type FacturaPayload } from '../../stores/facturas'
import { calcularTotales } from '../../lib/totalesDocumento'
import { mensajeDeFalla } from '../../lib/errors'
import { useConfirmarSalida } from '../../lib/salidaCaptura'
import AppLayout from '../../layouts/AppLayout.vue'
import PasosMostrador from '../../components/mostrador/PasosMostrador.vue'
import PasoArticulos from '../../components/mostrador/PasoArticulos.vue'
import PasoClienteFiscal from '../../components/mostrador/PasoClienteFiscal.vue'
import type { LineaEditable } from '../../components/DocumentoLineas.vue'
import type { ClienteResultado } from '../../components/ClienteCombobox.vue'
import UsoCfdiCombobox from '../../components/UsoCfdiCombobox.vue'
import FormaPagoSelect from '../../components/FormaPagoSelect.vue'
import MetodoPagoSelect from '../../components/MetodoPagoSelect.vue'
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
 * Factura capturada por pasos, hasta el timbrado (ver 029-pwa-mostrador.md).
 *
 * El paso de revisión no es un trámite de más: timbrar cuesta un folio, queda registrado ante la
 * autoridad y deshacerlo no es borrar sino cancelar con un motivo. Tres datos en una pantalla
 * limpia antes de apretar es barato comparado con una cancelación.
 */

const PASOS = ['Cliente', 'Artículos', 'Datos fiscales', 'Revisar', 'Listo']

const router = useRouter()
const facturas = useFacturasStore()

const paso = ref(0)

const cliente = ref<ClienteResultado | null>(null)
const lineas = ref<LineaEditable[]>([])

const usoCfdi = ref<string | null>(null)
const formaPago = ref<string | null>(null)
const metodoPago = ref<string | null>(null)

/** La factura, una vez guardada. Sobrevive a un timbrado fallido, que es lo que permite reintentar. */
const factura = ref<Factura | null>(null)
const timbrando = ref(false)
const error = ref<string | null>(null)

const correo = ref('')
const enviando = ref(false)
const avisoCorreo = ref<string | null>(null)

const totales = computed(() => calcularTotales(lineas.value, null, null))

const datosFiscalesCompletos = computed(
  () => usoCfdi.value !== null && formaPago.value !== null && metodoPago.value !== null,
)

const hayCaptura = computed(
  () => paso.value < 4 && (cliente.value !== null || lineas.value.length > 0),
)

const { confirmandoSalida, confirmarSalida, cancelarSalida } = useConfirmarSalida(
  () => hayCaptura.value,
)

function puedeAvanzar(): boolean {
  if (paso.value === 0) return cliente.value !== null
  if (paso.value === 1) return lineas.value.length > 0

  return datosFiscalesCompletos.value
}

/**
 * Guarda y timbra. `POST facturas` hace las dos cosas: si el timbrado falla, la factura **queda
 * guardada** con su motivo, que es como se comporta el timbrado del escritorio, y desde ahí se
 * reintenta solo el timbrado.
 */
async function timbrar() {
  timbrando.value = true
  error.value = null

  try {
    if (factura.value === null) {
      const payload: FacturaPayload = {
        cliente_id: cliente.value?.id ?? null,
        uso_cfdi: usoCfdi.value,
        forma_pago: formaPago.value,
        metodo_pago: metodoPago.value as FacturaPayload['metodo_pago'],
        descuento_global_tipo: null,
        descuento_global_valor: null,
        lineas: lineas.value,
        total: totales.value.total,
      }

      factura.value = await facturas.create(payload)
    } else {
      factura.value = await facturas.timbrar(factura.value.id)
    }

    if (factura.value.estado === 'timbrada') {
      correo.value = factura.value.cliente_correo ?? ''
      paso.value = 4
      return
    }

    error.value = factura.value.error_timbrado ?? 'No se pudo timbrar la factura.'
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    timbrando.value = false
  }
}

/**
 * Sin este botón el cliente se iría del mostrador con su factura timbrada y sin recibirla.
 */
async function enviarPorCorreo() {
  if (factura.value === null || correo.value.trim() === '') return

  enviando.value = true
  avisoCorreo.value = null

  try {
    await facturas.enviarCorreo(factura.value.id, [correo.value.trim()])
    avisoCorreo.value = 'Factura enviada.'
  } catch (err) {
    avisoCorreo.value = mensajeDeFalla(err)
  } finally {
    enviando.value = false
  }
}

function nuevaFactura() {
  cliente.value = null
  lineas.value = []
  usoCfdi.value = null
  formaPago.value = null
  metodoPago.value = null
  factura.value = null
  error.value = null
  correo.value = ''
  avisoCorreo.value = null
  paso.value = 0
}
</script>

<template>
  <AppLayout mostrador>
    <div class="mx-auto max-w-md space-y-5">
      <h1 class="font-heading text-foreground text-xl font-semibold">Generar factura</h1>

      <PasosMostrador :pasos="PASOS" :actual="paso" />

      <PasoClienteFiscal v-if="paso === 0" v-model="cliente" />

      <PasoArticulos v-else-if="paso === 1" v-model:lineas="lineas" />

      <div v-else-if="paso === 2" class="space-y-4">
        <div class="space-y-1.5">
          <Label>Uso de CFDI</Label>
          <UsoCfdiCombobox v-model="usoCfdi" />
        </div>
        <div class="space-y-1.5">
          <Label>Forma de pago</Label>
          <FormaPagoSelect v-model="formaPago" />
        </div>
        <div class="space-y-1.5">
          <Label>Método de pago</Label>
          <MetodoPagoSelect v-model="metodoPago" />
        </div>
      </div>

      <!-- Revisión: nombre, RFC y total, grandes y sin nada más alrededor. -->
      <div v-else-if="paso === 3" class="space-y-6">
        <div class="space-y-1 text-center">
          <p class="text-foreground text-2xl font-semibold">{{ cliente?.razon_social }}</p>
          <p class="text-muted-foreground font-mono text-lg">{{ cliente?.rfc }}</p>
        </div>

        <p class="text-center text-4xl font-semibold">${{ totales.total.toFixed(2) }}</p>

        <Alert v-if="error" variant="destructive">
          <AlertDescription>{{ error }}</AlertDescription>
        </Alert>
      </div>

      <!-- Resultado. -->
      <div v-else class="space-y-4">
        <div class="text-center">
          <p class="text-muted-foreground text-sm">Folio fiscal</p>
          <p class="font-mono text-sm break-all">{{ factura?.uuid_fiscal }}</p>
        </div>

        <div class="space-y-1.5">
          <Label for="correo-factura">Correo del cliente</Label>
          <Input id="correo-factura" v-model="correo" type="email" class="h-12 text-base" />
        </div>

        <Alert v-if="avisoCorreo">
          <AlertDescription>{{ avisoCorreo }}</AlertDescription>
        </Alert>

        <Button
          class="h-14 w-full text-base"
          :disabled="enviando || correo.trim() === ''"
          @click="enviarPorCorreo"
        >
          <EnvelopeIcon class="size-5" />
          {{ enviando ? 'Enviando...' : 'Enviar por correo' }}
        </Button>

        <div class="flex gap-2">
          <Button variant="outline" class="h-12 flex-1" @click="nuevaFactura">Nueva factura</Button>
          <Button variant="ghost" class="h-12 flex-1" @click="router.push({ name: 'dashboard' })">
            Inicio
          </Button>
        </div>
      </div>

      <div v-if="paso < 4" class="flex items-center gap-2 pt-2">
        <Button
          v-if="paso > 0"
          type="button"
          variant="outline"
          size="icon-lg"
          :disabled="timbrando"
          @click="paso -= 1"
        >
          <ArrowLeftIcon class="size-5" />
          <span class="sr-only">Paso anterior</span>
        </Button>

        <Button
          v-if="paso < 3"
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
          :disabled="timbrando"
          @click="timbrar"
        >
          {{ timbrando ? 'Timbrando...' : error ? 'Reintentar' : 'Timbrar' }}
        </Button>
      </div>
    </div>

    <Dialog :open="confirmandoSalida" @update:open="(v) => !v && cancelarSalida()">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>¿Salir de la factura?</DialogTitle>
          <DialogDescription>
            Lo que llevas capturado se pierde. La factura todavía no está timbrada.
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
