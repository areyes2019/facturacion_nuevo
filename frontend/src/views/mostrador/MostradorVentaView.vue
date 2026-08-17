<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowLeftIcon, ShareIcon } from '@heroicons/vue/24/outline'
import { usePedidosStore, type Pedido, type PedidoPayload } from '../../stores/pedidos'
import type { Cuenta } from '../../stores/cuentas'
import http from '../../lib/http'
import { calcularTotales } from '../../lib/totalesDocumento'
import { compartirArchivo } from '../../lib/compartir'
import { mensajeDeFalla } from '../../lib/errors'
import { useConfirmarSalida } from '../../lib/salidaCaptura'
import AppLayout from '../../layouts/AppLayout.vue'
import PasosMostrador from '../../components/mostrador/PasosMostrador.vue'
import PasoArticulosTarjetas from '../../components/mostrador/PasoArticulosTarjetas.vue'
import CarritoMostrador from '../../components/mostrador/CarritoMostrador.vue'
import type { LineaEditable } from '../../components/DocumentoLineas.vue'
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '../../components/ui/select'

/**
 * Venta al público, capturada por pasos (ver 029-pwa-mostrador.md).
 *
 * Es el pedido de mostrador de 027, no un documento nuevo: mismas validaciones, mismos folios,
 * mismos totales y el mismo ticket dibujado por el servidor. Lo único distinto es el camino, hecho
 * para el dedo y con un solo asunto por pantalla.
 *
 * **La etiqueta adhesiva no se imprime aquí.** Un celular no tiene la impresora conectada; la venta
 * queda registrada, cobrada y con su ticket en el teléfono del cliente, y la etiqueta se imprime
 * desde la computadora como hasta hoy.
 */

const PASOS = ['Cliente', 'Artículos', 'Carrito', 'Cobro', 'Listo']

const router = useRouter()
const pedidos = usePedidosStore()

const paso = ref(0)

const form = reactive({
  cliente_nombre: '',
  cliente_telefono: '',
  cliente_correo: '',
})

const lineas = ref<LineaEditable[]>([])

/** Datos del último pedido con este teléfono: una sugerencia que se acepta con un toque. */
const sugerencia = ref<{ cliente_nombre: string; cliente_correo: string | null } | null>(null)

const cuentas = ref<Cuenta[]>([])
const cuentaId = ref<number | null>(null)
const errorCuentas = ref<string | null>(null)
const monto = ref<number | null>(null)

/** El pedido, una vez creado. Sobrevive a un cobro fallido: existe aunque el pago no entrara. */
const pedido = ref<Pedido | null>(null)
const cobrando = ref(false)
const errorPedido = ref<string | null>(null)
const errorPago = ref<string | null>(null)

const ticketUrl = ref<string | null>(null)
const ticketBlob = ref<Blob | null>(null)
const compartiendo = ref(false)
const avisoTicket = ref<string | null>(null)

const totales = computed(() => calcularTotales(lineas.value, null, null))

const clienteCompleto = computed(
  () => form.cliente_nombre.trim() !== '' && form.cliente_telefono.trim() !== '',
)

const hayCaptura = computed(
  () => paso.value < 4 && (clienteCompleto.value || lineas.value.length > 0),
)

const { confirmandoSalida, confirmarSalida, cancelarSalida } = useConfirmarSalida(
  () => hayCaptura.value,
)

onMounted(cargarCuentas)

onBeforeUnmount(() => {
  if (ticketUrl.value) URL.revokeObjectURL(ticketUrl.value)
})

/**
 * La caja viene preseleccionada: es la cuenta de efectivo activa más antigua, que en el mostrador
 * es donde entra casi todo. Se puede cambiar a otra cuenta con un toque.
 */
async function cargarCuentas() {
  errorCuentas.value = null

  try {
    const { data } = await http.get('/cuentas', { params: { per_page: 100, activa: 'true' } })
    cuentas.value = (data.data as Cuenta[]).slice().sort((a, b) => a.id - b.id)
    cuentaId.value =
      (cuentas.value.find((c) => c.tipo === 'efectivo') ?? cuentas.value[0])?.id ?? null
  } catch (err) {
    errorCuentas.value = mensajeDeFalla(err)
  }
}

/**
 * Al terminar de capturar el teléfono se busca si ese número ya compró antes. No se dispara en cada
 * tecla: sería una petición por dígito para responder algo que solo tiene sentido con el número
 * completo.
 */
async function buscarClienteAnterior() {
  sugerencia.value = null

  const telefono = form.cliente_telefono.trim()
  if (telefono.length < 8) return

  try {
    const resultado = await pedidos.buscarPorTelefono(telefono)

    if (resultado.encontrado && resultado.cliente_nombre) {
      sugerencia.value = {
        cliente_nombre: resultado.cliente_nombre,
        cliente_correo: resultado.cliente_correo ?? null,
      }
    }
  } catch {
    // Una sugerencia que falla no puede estorbar la captura de la venta.
  }
}

function aplicarSugerencia() {
  if (!sugerencia.value) return

  form.cliente_nombre = sugerencia.value.cliente_nombre
  form.cliente_correo = sugerencia.value.cliente_correo ?? ''
  sugerencia.value = null
}

function avanzar() {
  // Al salir del carrito, el cobro arranca con el total ya escrito; bajarlo es registrar anticipo.
  if (paso.value === 2) monto.value = totales.value.total

  paso.value += 1
}

function retroceder() {
  paso.value -= 1
}

/**
 * El cobro son **dos peticiones**: crear el pedido y registrar el pago. Si la segunda falla, el
 * pedido ya existe y la pantalla lo dice con su número de ticket. Callarlo llevaría a capturar la
 * venta otra vez y a terminar con dos pedidos por una sola compra.
 */
async function cobrar() {
  if (pedido.value === null) {
    const payload: PedidoPayload = {
      cliente_nombre: form.cliente_nombre.trim(),
      cliente_telefono: form.cliente_telefono.trim(),
      cliente_correo: form.cliente_correo.trim() || null,
      descuento_global_tipo: null,
      descuento_global_valor: null,
      lineas: lineas.value.map((linea) => ({
        articulo_id: linea.articulo_id,
        cantidad: linea.cantidad,
        descripcion: linea.descripcion,
        modelo: linea.modelo || null,
        precio_unitario: linea.precio_unitario,
        descuento_tipo: linea.descuento_tipo,
        descuento_valor: linea.descuento_valor,
        tasa_iva: linea.tasa_iva,
      })),
      total: totales.value.total,
    }

    cobrando.value = true
    errorPedido.value = null

    try {
      pedido.value = await pedidos.create(payload)
    } catch (err) {
      errorPedido.value = mensajeDeFalla(err)
      return
    } finally {
      cobrando.value = false
    }
  }

  await registrarCobro()
}

async function registrarCobro() {
  if (pedido.value === null || monto.value === null || cuentaId.value === null) return

  cobrando.value = true
  errorPago.value = null

  try {
    pedido.value = await pedidos.registrarPago(pedido.value.id, {
      fecha_pago: new Date().toISOString().slice(0, 10),
      monto: monto.value,
      cuenta_id: cuentaId.value,
    })

    paso.value = 4
    await cargarTicket()
  } catch (err) {
    errorPago.value = mensajeDeFalla(err)
  } finally {
    cobrando.value = false
  }
}

async function cargarTicket() {
  if (pedido.value === null) return

  if (ticketUrl.value) URL.revokeObjectURL(ticketUrl.value)

  try {
    ticketBlob.value = await pedidos.ticketBlob(pedido.value.id)
    ticketUrl.value = URL.createObjectURL(ticketBlob.value)
  } catch {
    ticketBlob.value = null
    ticketUrl.value = null
  }
}

async function compartirTicket() {
  if (pedido.value === null || ticketBlob.value === null) return

  compartiendo.value = true
  avisoTicket.value = null

  try {
    const resultado = await compartirArchivo(
      ticketBlob.value,
      `ticket-${pedido.value.numero_ticket}.jpg`,
      pedido.value.mensaje_compartible ?? '',
    )

    if (resultado === 'descargado') {
      avisoTicket.value = 'Ticket descargado: adjúntalo en la ventana de WhatsApp que se abrió.'
    }
  } catch {
    avisoTicket.value = 'No se pudo compartir el ticket.'
  } finally {
    compartiendo.value = false
  }
}

function nuevaVenta() {
  if (ticketUrl.value) URL.revokeObjectURL(ticketUrl.value)

  form.cliente_nombre = ''
  form.cliente_telefono = ''
  form.cliente_correo = ''
  lineas.value = []
  sugerencia.value = null
  pedido.value = null
  monto.value = null
  errorPedido.value = null
  errorPago.value = null
  ticketBlob.value = null
  ticketUrl.value = null
  avisoTicket.value = null
  paso.value = 0
}
</script>

<template>
  <AppLayout mostrador>
    <div class="mx-auto max-w-md space-y-5">
      <h1 class="font-heading text-foreground text-xl font-semibold">Venta al público</h1>

      <PasosMostrador :pasos="PASOS" :actual="paso" />

      <!-- Paso 1: cliente. -->
      <div v-if="paso === 0" class="space-y-4">
        <div class="space-y-1.5">
          <Label for="telefono">Teléfono</Label>
          <Input
            id="telefono"
            v-model="form.cliente_telefono"
            inputmode="tel"
            autocomplete="off"
            class="h-12 text-base"
            @blur="buscarClienteAnterior"
          />
        </div>

        <Alert v-if="sugerencia">
          <AlertDescription class="space-y-2">
            <p>
              Este teléfono ya compró antes, a nombre de
              <strong>{{ sugerencia.cliente_nombre }}</strong
              >.
            </p>
            <div class="flex gap-2">
              <Button type="button" size="sm" @click="aplicarSugerencia">Usar esos datos</Button>
              <Button type="button" size="sm" variant="ghost" @click="sugerencia = null">
                Ignorar
              </Button>
            </div>
          </AlertDescription>
        </Alert>

        <div class="space-y-1.5">
          <Label for="nombre">Nombre</Label>
          <Input
            id="nombre"
            v-model="form.cliente_nombre"
            autocomplete="off"
            class="h-12 text-base"
          />
        </div>

        <div class="space-y-1.5">
          <Label for="correo">Correo (opcional)</Label>
          <Input
            id="correo"
            v-model="form.cliente_correo"
            type="email"
            autocomplete="off"
            class="h-12 text-base"
          />
        </div>
      </div>

      <!-- Paso 2: artículos, con línea libre. -->
      <PasoArticulosTarjetas
        v-else-if="paso === 1"
        v-model:lineas="lineas"
        permite-linea-libre
        etiqueta-terminar="Terminar venta"
        @terminar="avanzar"
      />

      <CarritoMostrador v-else-if="paso === 2" v-model:lineas="lineas" />

      <!-- Paso 3: cobro. -->
      <div v-else-if="paso === 3" class="space-y-5">
        <div class="border-border flex items-baseline justify-between border-b pb-3">
          <span class="text-muted-foreground">Total de la venta</span>
          <span class="text-3xl font-semibold">${{ totales.total.toFixed(2) }}</span>
        </div>

        <div class="space-y-1.5">
          <Label for="monto">Monto a cobrar</Label>
          <Input
            id="monto"
            :model-value="monto ?? undefined"
            type="number"
            inputmode="decimal"
            min="0.01"
            step="0.01"
            class="h-12 text-base"
            @update:model-value="(v) => (monto = v === '' ? null : Number(v))"
          />
          <p class="text-muted-foreground text-sm">
            Bájalo para registrar un anticipo; el saldo se cobra al entregar.
          </p>
        </div>

        <div class="space-y-1.5">
          <Label>¿A qué cuenta entra el dinero?</Label>
          <Select
            :model-value="cuentaId?.toString() ?? undefined"
            @update:model-value="(v) => (cuentaId = v ? Number(v) : null)"
          >
            <SelectTrigger class="h-12 w-full">
              <SelectValue placeholder="Selecciona una cuenta" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem v-for="cuenta in cuentas" :key="cuenta.id" :value="cuenta.id.toString()">
                {{ cuenta.nombre }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <Alert v-if="errorCuentas" variant="destructive">
          <AlertDescription class="space-y-2">
            <p>{{ errorCuentas }}</p>
            <Button type="button" size="sm" variant="outline" @click="cargarCuentas">
              Reintentar
            </Button>
          </AlertDescription>
        </Alert>

        <Alert v-if="errorPedido" variant="destructive">
          <AlertDescription>{{ errorPedido }}</AlertDescription>
        </Alert>

        <!-- El pedido ya existe y solo falló el cobro: se reintenta el cobro, no la venta. -->
        <Alert v-if="errorPago && pedido" variant="destructive">
          <AlertDescription class="space-y-2">
            <p>
              La venta quedó registrada con el ticket
              <strong class="font-mono">{{ pedido.numero_ticket }}</strong
              >, pero el cobro no entró: {{ errorPago }}
            </p>
            <Button type="button" size="sm" :disabled="cobrando" @click="registrarCobro">
              Reintentar solo el cobro
            </Button>
          </AlertDescription>
        </Alert>
      </div>

      <!-- Paso 4: listo. -->
      <div v-else class="space-y-4 text-center">
        <p class="text-muted-foreground">
          Ticket <strong class="text-foreground font-mono">{{ pedido?.numero_ticket }}</strong>
        </p>

        <img
          v-if="ticketUrl"
          :src="ticketUrl"
          alt="Ticket de compra"
          class="border-border mx-auto max-w-full rounded border"
        />
        <p v-else class="text-muted-foreground text-sm">
          No se pudo dibujar el ticket, pero la venta quedó registrada.
        </p>

        <Alert v-if="avisoTicket">
          <AlertDescription>{{ avisoTicket }}</AlertDescription>
        </Alert>

        <Button
          class="h-14 w-full text-base"
          :disabled="compartiendo || !ticketBlob"
          @click="compartirTicket"
        >
          <ShareIcon class="size-5" />
          {{ compartiendo ? 'Compartiendo...' : 'Compartir por WhatsApp' }}
        </Button>

        <div class="flex gap-2">
          <Button variant="outline" class="h-12 flex-1" @click="nuevaVenta">Nueva venta</Button>
          <Button variant="ghost" class="h-12 flex-1" @click="router.push({ name: 'dashboard' })">
            Inicio
          </Button>
        </div>
      </div>

      <!-- Barra de avance: un solo botón grande, y el regreso discreto a su izquierda. El paso de
           artículos no aparece aquí: lo cierra el botón de su propio pie. -->
      <div v-if="paso !== 1 && paso < 4" class="flex items-center gap-2 pt-2">
        <Button v-if="paso > 0" type="button" variant="outline" size="icon-lg" @click="retroceder">
          <ArrowLeftIcon class="size-5" />
          <span class="sr-only">Paso anterior</span>
        </Button>

        <Button
          v-if="paso === 0 || paso === 2"
          type="button"
          class="h-14 flex-1 text-base"
          :disabled="paso === 0 ? !clienteCompleto : lineas.length === 0"
          @click="avanzar"
        >
          Siguiente
        </Button>

        <Button
          v-else-if="paso === 3"
          type="button"
          class="h-14 flex-1 text-base"
          :disabled="cobrando || cuentaId === null || (monto ?? 0) <= 0"
          @click="cobrar"
        >
          {{ cobrando ? 'Registrando...' : `Cobrar $${(monto ?? 0).toFixed(2)}` }}
        </Button>
      </div>
    </div>

    <Dialog :open="confirmandoSalida" @update:open="(v) => !v && cancelarSalida()">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>¿Salir de la venta?</DialogTitle>
          <DialogDescription>
            Lo que llevas capturado se pierde. La venta todavía no está registrada.
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
