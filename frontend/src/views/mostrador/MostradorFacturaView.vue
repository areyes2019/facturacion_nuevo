<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftIcon, EnvelopeIcon, ShareIcon } from '@heroicons/vue/24/outline'
import { useFacturasStore, type Factura, type FacturaPayload } from '../../stores/facturas'
import { calcularTotales } from '../../lib/totalesDocumento'
import { reaplicarDescuento } from '../../lib/lineasMostrador'
import { mensajeDeFalla, mensajeDeFallaDeDescarga } from '../../lib/errors'
import type { ArchivoCompartible } from '../../lib/compartir'
import { useConfirmarSalida } from '../../lib/salidaCaptura'
import AppLayout from '../../layouts/AppLayout.vue'
import PasosMostrador from '../../components/mostrador/PasosMostrador.vue'
import PasoArticulosTarjetas from '../../components/mostrador/PasoArticulosTarjetas.vue'
import PasoClienteTarjetas from '../../components/mostrador/PasoClienteTarjetas.vue'
import PasoOpciones from '../../components/mostrador/PasoOpciones.vue'
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
 * Factura capturada por pasos, hasta el timbrado (ver 029-pwa-mostrador.md).
 *
 * Los tres datos fiscales tienen **una pantalla cada uno**: son listas del SAT en las que hay que
 * encontrar algo, y encontrarlo en un `<select>` de celular —una lista dentro de una ventanita, sin
 * buscador— es la parte más incómoda de la captura.
 *
 * El paso de revisión no es un trámite de más: timbrar cuesta un folio, queda registrado ante la
 * autoridad y deshacerlo no es borrar sino cancelar con un motivo. Una pantalla limpia antes de
 * apretar es barata comparada con una cancelación.
 */

const PASOS = [
  'Cliente',
  'Artículos',
  'Carrito',
  'Uso de CFDI',
  'Forma de pago',
  'Método de pago',
  'Revisar',
  'Listo',
]

/** Son dos y son siempre los mismos, así que van escritos aquí y no salen de una petición. */
const METODOS_PAGO = [
  { id: 'PUE', texto: 'Pago en una sola exhibición' },
  { id: 'PPD', texto: 'Pago en parcialidades o diferido' },
]

const router = useRouter()
const facturas = useFacturasStore()

const paso = ref(0)

const cliente = ref<ClienteResultado | null>(null)
const lineas = ref<LineaEditable[]>([])

const usoCfdi = ref<string | null>(null)
const usoCfdiTexto = ref('')
const formaPago = ref<string | null>(null)
const formaPagoTexto = ref('')
const metodoPago = ref<string | null>(null)

/** La factura, una vez guardada. Sobrevive a un timbrado fallido, que es lo que permite reintentar. */
const factura = ref<Factura | null>(null)
const timbrando = ref(false)
const error = ref<string | null>(null)

const correo = ref('')
const enviando = ref(false)
const dialogoCorreo = ref(false)
const compartiendo = ref(false)
const avisoEnvio = ref<string | null>(null)

/** El PDF ya bajado, listo para el menú de compartir (ver 029, supuesto 78). */
const archivo = ref<ArchivoCompartible | null>(null)

const totales = computed(() => calcularTotales(lineas.value, null, null, true))

const metodoPagoTexto = computed(
  () => METODOS_PAGO.find((metodo) => metodo.id === metodoPago.value)?.texto ?? '',
)

const hayCaptura = computed(
  () => paso.value < 7 && (cliente.value !== null || lineas.value.length > 0),
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

function elegirMetodoPago(id: string) {
  metodoPago.value = id
  paso.value = 6
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
      paso.value = 7
      void prepararEnvio()
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
 * Baja el PDF en cuanto la factura queda timbrada: al tocar el botón ya no puede haber esperas de
 * por medio o el menú del aparato se rechaza (ver 029, supuesto 78).
 */
async function prepararEnvio() {
  if (factura.value === null) return

  compartiendo.value = true
  avisoEnvio.value = null

  try {
    archivo.value = await facturas.archivoParaWhatsapp(factura.value)
  } catch (err) {
    avisoEnvio.value = await mensajeDeFallaDeDescarga(err)
  } finally {
    compartiendo.value = false
  }
}

/**
 * Por WhatsApp va **solo el PDF**: Chrome no admite `.xml` entre los tipos que su menú comparte, y
 * un grupo con el XML dentro se rechaza entero. El XML sale por correo, que es el botón de abajo
 * (ver 029, supuestos 81 y 82).
 */
async function compartirPorWhatsapp() {
  if (factura.value === null || archivo.value === null) return

  avisoEnvio.value = null

  try {
    const resultado = await facturas.compartirPorWhatsapp(factura.value, archivo.value)

    if (resultado === 'descargado') {
      avisoEnvio.value = 'PDF descargado: adjúntalo en la ventana de WhatsApp que acaba de abrirse.'
    } else if (resultado === 'compartido') {
      avisoEnvio.value = 'Factura compartida. El XML va por correo.'
    }
  } catch (err) {
    avisoEnvio.value = mensajeDeFalla(err)
  }
}

/**
 * Sin este botón el cliente se iría del mostrador con su factura timbrada y sin recibirla.
 */
async function enviarPorCorreo() {
  if (factura.value === null || correo.value.trim() === '') return

  enviando.value = true
  avisoEnvio.value = null

  try {
    await facturas.enviarCorreo(factura.value.id, [correo.value.trim()])
    dialogoCorreo.value = false
    avisoEnvio.value = 'Factura enviada por correo.'
  } catch (err) {
    avisoEnvio.value = mensajeDeFalla(err)
  } finally {
    enviando.value = false
  }
}

function nuevaFactura() {
  cliente.value = null
  lineas.value = []
  usoCfdi.value = null
  usoCfdiTexto.value = ''
  formaPago.value = null
  formaPagoTexto.value = ''
  metodoPago.value = null
  factura.value = null
  error.value = null
  correo.value = ''
  avisoEnvio.value = null
  archivo.value = null
  paso.value = 0
}
</script>

<template>
  <AppLayout mostrador>
    <div class="mx-auto max-w-md space-y-5">
      <h1 class="font-heading text-foreground text-xl font-semibold">Generar factura</h1>

      <PasosMostrador :pasos="PASOS" :actual="paso" />

      <PasoClienteTarjetas v-if="paso === 0" recomendar-constancia @elegido="onClienteElegido" />

      <PasoArticulosTarjetas
        v-else-if="paso === 1"
        v-model:lineas="lineas"
        :descuento-porcentaje="cliente?.descuento_permanente ?? 0"
        etiqueta-terminar="Terminar factura"
        @terminar="paso = 2"
      />

      <CarritoMostrador v-else-if="paso === 2" v-model:lineas="lineas" />

      <PasoOpciones
        v-else-if="paso === 3"
        v-model="usoCfdi"
        url="/catalogos/usos-cfdi"
        placeholder="Buscar uso de CFDI..."
        @elegido="
          (opcion) => {
            usoCfdiTexto = opcion.texto
            paso = 4
          }
        "
      />

      <PasoOpciones
        v-else-if="paso === 4"
        v-model="formaPago"
        url="/catalogos/formas-pago"
        placeholder="Buscar forma de pago..."
        @elegido="
          (opcion) => {
            formaPagoTexto = opcion.texto
            paso = 5
          }
        "
      />

      <!-- Método de pago: dos botones y nada más. La clave sola no dice nada, así que va con su
           nombre completo debajo. -->
      <div v-else-if="paso === 5" class="space-y-3">
        <button
          v-for="metodo in METODOS_PAGO"
          :key="metodo.id"
          type="button"
          class="border-border bg-background hover:bg-accent focus-visible:ring-ring w-full rounded-lg border p-6 text-left focus-visible:ring-2 focus-visible:outline-none"
          :class="metodo.id === metodoPago ? 'border-primary' : ''"
          @click="elegirMetodoPago(metodo.id)"
        >
          <p class="text-foreground font-mono text-2xl font-semibold">{{ metodo.id }}</p>
          <p class="text-muted-foreground">{{ metodo.texto }}</p>
        </button>
      </div>

      <!-- Revisión: nombre, RFC y total, grandes; los tres datos fiscales debajo, en letra chica. -->
      <div v-else-if="paso === 6" class="space-y-6">
        <div class="space-y-1 text-center">
          <p class="text-foreground text-2xl font-semibold">{{ cliente?.razon_social }}</p>
          <p class="text-muted-foreground font-mono text-lg">{{ cliente?.rfc }}</p>
        </div>

        <p class="text-center text-4xl font-semibold">${{ totales.total.toFixed(2) }}</p>

        <dl class="text-muted-foreground space-y-1 text-sm">
          <div class="flex justify-between gap-4">
            <dt>Uso de CFDI</dt>
            <dd class="text-right">{{ usoCfdi }} · {{ usoCfdiTexto }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt>Forma de pago</dt>
            <dd class="text-right">{{ formaPago }} · {{ formaPagoTexto }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt>Método de pago</dt>
            <dd class="text-right">{{ metodoPago }} · {{ metodoPagoTexto }}</dd>
          </div>
        </dl>

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
          <Button variant="outline" class="h-12 flex-1" @click="nuevaFactura">Nueva factura</Button>
          <Button variant="ghost" class="h-12 flex-1" @click="router.push({ name: 'dashboard' })">
            Inicio
          </Button>
        </div>
      </div>

      <!-- El paso de cliente, los dos de catálogo y el de método de pago eligen tocando, y el de
           artículos se cierra con el botón de su pie: ninguno necesita "Siguiente". -->
      <div v-if="paso >= 2 && paso <= 6" class="flex items-center gap-2 pt-2">
        <Button
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
          v-if="paso === 2"
          type="button"
          class="h-14 flex-1 text-base"
          :disabled="lineas.length === 0"
          @click="paso += 1"
        >
          Siguiente
        </Button>

        <Button
          v-else-if="paso === 6"
          type="button"
          class="h-14 flex-1 text-base"
          :disabled="timbrando"
          @click="timbrar"
        >
          {{ timbrando ? 'Timbrando...' : error ? 'Reintentar' : 'Timbrar' }}
        </Button>
      </div>
    </div>

    <Dialog v-model:open="dialogoCorreo">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Enviar por correo</DialogTitle>
          <DialogDescription>
            Sale del servidor con el PDF y el XML adjuntos. Viene el correo del cliente; puedes
            cambiarlo.
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-1.5">
          <Label for="correo-factura">Correo</Label>
          <Input id="correo-factura" v-model="correo" type="email" class="h-12 text-base" />
        </div>

        <DialogFooter>
          <Button variant="outline" @click="dialogoCorreo = false">Cancelar</Button>
          <Button :disabled="enviando || correo.trim() === ''" @click="enviarPorCorreo">
            {{ enviando ? 'Enviando...' : 'Enviar' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

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
