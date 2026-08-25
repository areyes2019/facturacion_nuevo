<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  ArrowLeftIcon,
  BanknotesIcon,
  PhotoIcon,
  ShareIcon,
  TrashIcon,
  TruckIcon,
} from '@heroicons/vue/24/outline'
import {
  useOrdenesTrabajoStore,
  type OrdenTrabajo,
  type TarifaEnvio,
  type FormaPagoEnvio,
} from '../stores/ordenesTrabajo'
import { usePedidosStore } from '../stores/pedidos'
import { useCotizacionesStore } from '../stores/cotizaciones'
import { useConfiguracionStore } from '../stores/configuracion'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { compartirTexto } from '../lib/compartir'
import { tipoDePago } from '../lib/pagoCotizacion'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Badge } from '../components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '../components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../components/ui/dialog'
import CuentaSelect from '../components/CuentaSelect.vue'

/**
 * Detalle de la Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md): qué fabricar, para quién,
 * cómo debe quedar, y las acciones para moverla por el tablero.
 */
const route = useRoute()
const router = useRouter()
const ordenesTrabajo = useOrdenesTrabajoStore()
const pedidos = usePedidosStore()
const cotizaciones = useCotizacionesStore()
const configuracion = useConfiguracionStore()

const ordenId = computed(() => Number(route.params.id))

const orden = ref<OrdenTrabajo | null>(null)
const cargando = ref(true)
const errorGeneral = ref<string | null>(null)
const accionando = ref(false)

const ESTADO_TEXTO: Record<string, string> = {
  pendiente: 'Pendiente',
  en_produccion: 'En producción',
  listo_para_entregar: 'Listo para entregar',
  a_domicilio: 'A domicilio',
  entregado: 'Entregado',
}

const ESTADO_VARIANTE: Record<string, 'secondary' | 'warning' | 'success' | 'default' | 'outline'> =
  {
    pendiente: 'secondary',
    en_produccion: 'warning',
    listo_para_entregar: 'success',
    a_domicilio: 'default',
    entregado: 'outline',
  }

async function cargar() {
  cargando.value = true
  errorGeneral.value = null
  try {
    orden.value = await ordenesTrabajo.fetchOne(ordenId.value)
    observaciones.value = orden.value.observaciones ?? ''
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

// ---------------------------------------------------------------------------
// Observaciones
// ---------------------------------------------------------------------------
const observaciones = ref('')
const guardandoObservaciones = ref(false)
const hayCambiosObservaciones = computed(
  () => observaciones.value !== (orden.value?.observaciones ?? ''),
)

async function guardarObservaciones() {
  if (!orden.value) return
  guardandoObservaciones.value = true
  errorGeneral.value = null
  try {
    orden.value = await ordenesTrabajo.actualizarObservaciones(orden.value.id, observaciones.value)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    guardandoObservaciones.value = false
  }
}

// ---------------------------------------------------------------------------
// Imagen del diseño
// ---------------------------------------------------------------------------
const subiendoImagen = ref(false)
const inputImagen = ref<HTMLInputElement | null>(null)

function elegirImagen() {
  inputImagen.value?.click()
}

async function onArchivoImagen(evento: Event) {
  const archivo = (evento.target as HTMLInputElement).files?.[0]
  if (!archivo || !orden.value) return

  subiendoImagen.value = true
  errorGeneral.value = null
  try {
    orden.value = await ordenesTrabajo.subirImagen(orden.value.id, archivo)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    subiendoImagen.value = false
    if (inputImagen.value) inputImagen.value.value = ''
  }
}

async function quitarImagen() {
  if (!orden.value) return
  subiendoImagen.value = true
  try {
    await ordenesTrabajo.eliminarImagen(orden.value.id)
    orden.value = { ...orden.value, imagen_url: null }
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    subiendoImagen.value = false
  }
}

// ---------------------------------------------------------------------------
// Transiciones de estado
// ---------------------------------------------------------------------------
async function iniciarProduccion() {
  if (!orden.value) return
  accionando.value = true
  errorGeneral.value = null
  try {
    orden.value = await ordenesTrabajo.iniciarProduccion(orden.value.id)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    accionando.value = false
  }
}

async function marcarListo() {
  if (!orden.value) return
  accionando.value = true
  errorGeneral.value = null
  try {
    orden.value = await ordenesTrabajo.marcarListo(orden.value.id)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    accionando.value = false
  }
}

async function marcarEntregado() {
  if (!orden.value) return
  accionando.value = true
  errorGeneral.value = null
  try {
    orden.value = await ordenesTrabajo.entregar(orden.value.id)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    accionando.value = false
  }
}

/**
 * Entrega en mostrador (ver 039-qr-conductor-produccion.md): quien la escanea llega directo a esta
 * ficha, así que "entregado" se marca aquí, sobre el documento origen — sin `cuenta_id`, para que
 * cierre sin cobrar solo. El saldo, si queda, se cobra aparte con el botón "Cobrar".
 */
async function marcarEntregadoMostrador() {
  if (!orden.value) return
  accionando.value = true
  errorGeneral.value = null
  try {
    if (orden.value.documentable_type === 'pedido') {
      await pedidos.entregar(orden.value.documentable_id)
    } else {
      await cotizaciones.entregar(orden.value.documentable_id)
    }
    orden.value = await ordenesTrabajo.fetchOne(orden.value.id)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    accionando.value = false
  }
}

// ---------------------------------------------------------------------------
// Cobro del saldo pendiente, aparte de la entrega (ver 039-qr-conductor-produccion.md)
// ---------------------------------------------------------------------------
const dialogoCobro = ref(false)
const cobrando = ref(false)
const errorCobro = ref<string | null>(null)
const montoCobro = ref<number | null>(null)
const cuentaCobro = ref<number | null>(null)
const fechaCobro = ref(new Date().toISOString().slice(0, 10))

function abrirCobro() {
  if (!orden.value) return
  montoCobro.value = orden.value.saldo_pendiente
  cuentaCobro.value = null
  fechaCobro.value = new Date().toISOString().slice(0, 10)
  errorCobro.value = null
  dialogoCobro.value = true
}

/**
 * Reutiliza el mismo endpoint de pagos que ya usan las pantallas de Pedido/Cotización — no hay
 * formulario de cobro nuevo, solo se captura aquí para no salir de la ficha. Para Cotización, el
 * `tipo` se deduce igual que en el mostrador (ver `lib/pagoCotizacion.ts`), consultando sus pagos
 * previos porque la ficha de Producción no los trae cargados.
 */
async function confirmarCobro() {
  if (!orden.value || montoCobro.value === null || cuentaCobro.value === null) return

  cobrando.value = true
  errorCobro.value = null

  try {
    if (orden.value.documentable_type === 'pedido') {
      await pedidos.registrarPago(orden.value.documentable_id, {
        fecha_pago: fechaCobro.value,
        monto: montoCobro.value,
        cuenta_id: cuentaCobro.value,
      })
    } else {
      const cotizacion = await cotizaciones.fetchOne(orden.value.documentable_id)
      const tipo = tipoDePago(cotizacion.saldo_pendiente, montoCobro.value, cotizacion.pagos.length)

      await cotizaciones.registrarPago(orden.value.documentable_id, {
        tipo,
        fecha_pago: fechaCobro.value,
        monto: tipo === 'anticipo' ? montoCobro.value : null,
        cuenta_id: cuentaCobro.value,
      })
    }

    dialogoCobro.value = false
    orden.value = await ordenesTrabajo.fetchOne(orden.value.id)
  } catch (err) {
    errorCobro.value = extractErrorMessage(err)
  } finally {
    cobrando.value = false
  }
}

// ---------------------------------------------------------------------------
// Envío a domicilio
// ---------------------------------------------------------------------------
const dialogoEnvio = ref(false)
const guardandoEnvio = ref(false)
const erroresEnvio = ref<Record<string, string>>({})
const errorEnvio = ref<string | null>(null)

const formEnvio = ref({
  nombre_receptor: '',
  telefono_receptor: '',
  fecha_recepcion: new Date().toISOString().slice(0, 10),
  hora_recepcion: '',
  tarifa: 'a' as TarifaEnvio,
  forma_pago: 'por_cobrar' as FormaPagoEnvio,
  cuenta_id: null as number | null,
})

function abrirEnvio() {
  errorEnvio.value = null
  erroresEnvio.value = {}
  dialogoEnvio.value = true
}

const tarifaMonto = computed(() => {
  const claves = { a: 'envio_tarifa_a', b: 'envio_tarifa_b', c: 'envio_tarifa_c' } as const
  const valores = configuracion.valores
  if (!valores) return null
  return Number(valores[claves[formEnvio.value.tarifa]])
})

async function guardarEnvio() {
  if (!orden.value) return
  guardandoEnvio.value = true
  errorEnvio.value = null
  erroresEnvio.value = {}

  try {
    orden.value = await ordenesTrabajo.crearEnvio(orden.value.id, {
      nombre_receptor: formEnvio.value.nombre_receptor,
      telefono_receptor: formEnvio.value.telefono_receptor,
      fecha_recepcion: formEnvio.value.fecha_recepcion,
      hora_recepcion: formEnvio.value.hora_recepcion,
      tarifa: formEnvio.value.tarifa,
      forma_pago: formEnvio.value.forma_pago,
      ...(formEnvio.value.forma_pago === 'prepagado' && formEnvio.value.cuenta_id
        ? { cuenta_id: formEnvio.value.cuenta_id }
        : {}),
    })
    dialogoEnvio.value = false
  } catch (err) {
    erroresEnvio.value = extractFieldErrors(err)
    errorEnvio.value = extractErrorMessage(err)
  } finally {
    guardandoEnvio.value = false
  }
}

onMounted(() => {
  configuracion.fetch().catch(() => {})
})

// ---------------------------------------------------------------------------
// Ficha de envío
// ---------------------------------------------------------------------------
const compartiendoFicha = ref(false)

const fichaEnvioTexto = computed(() => {
  if (!orden.value?.envio) return ''
  const envio = orden.value.envio
  const importePendiente =
    envio.forma_pago === 'por_cobrar'
      ? (orden.value.saldo_pendiente + envio.monto).toFixed(2)
      : orden.value.saldo_pendiente.toFixed(2)

  return [
    'ENVÍO',
    '',
    `Cliente: ${orden.value.cliente_nombre ?? ''}`,
    `Teléfono: ${orden.value.cliente_telefono ?? ''}`,
    `Nombre de quien recibe: ${envio.nombre_receptor}`,
    `Teléfono de quien recibe: ${envio.telefono_receptor}`,
    `Ticket: ${orden.value.documento_etiqueta}`,
    `Número de orden: ${orden.value.folio_formateado}`,
    `Importe pendiente: $${importePendiente}`,
    `Estado del pago: ${envio.forma_pago === 'prepagado' ? 'Prepagado' : 'Por cobrar'}`,
    `Fecha: ${envio.fecha_recepcion}`,
    `Hora: ${envio.hora_recepcion}`,
  ].join('\n')
})

async function compartirFicha() {
  compartiendoFicha.value = true
  try {
    await compartirTexto(fichaEnvioTexto.value)
  } catch {
    errorGeneral.value = 'No se pudo compartir la ficha.'
  } finally {
    compartiendoFicha.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-4xl space-y-4">
      <Button variant="ghost" size="sm" @click="router.push({ name: 'produccion' })">
        <ArrowLeftIcon class="size-4" />
        Producción
      </Button>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <p v-if="cargando" class="text-muted-foreground text-center">Cargando...</p>

      <template v-else-if="orden">
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="font-heading text-foreground text-xl font-semibold">
            {{ orden.folio_formateado }}
          </h1>
          <Badge :variant="ESTADO_VARIANTE[orden.estado]">{{ ESTADO_TEXTO[orden.estado] }}</Badge>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
          <div class="min-w-0 space-y-4">
            <Card>
              <CardHeader>
                <CardTitle class="text-base">Qué fabricar</CardTitle>
              </CardHeader>
              <CardContent class="grid gap-2 text-sm sm:grid-cols-2">
                <div>
                  <p class="text-muted-foreground">Cliente</p>
                  <p>{{ orden.cliente_nombre }}</p>
                </div>
                <div>
                  <p class="text-muted-foreground">Teléfono</p>
                  <p>{{ orden.cliente_telefono }}</p>
                </div>
                <div>
                  <p class="text-muted-foreground">Producto</p>
                  <p>{{ orden.producto }}</p>
                </div>
                <div>
                  <p class="text-muted-foreground">Ticket</p>
                  <RouterLink
                    v-if="orden.documentable_type === 'pedido'"
                    class="text-primary underline"
                    :to="{ name: 'pedidos-detalle', params: { id: orden.documentable_id } }"
                  >
                    {{ orden.documento_etiqueta }}
                  </RouterLink>
                  <RouterLink
                    v-else
                    class="text-primary underline"
                    :to="{ name: 'cotizaciones-detalle', params: { id: orden.documentable_id } }"
                  >
                    {{ orden.documento_etiqueta }}
                  </RouterLink>
                </div>
                <div v-if="orden.saldo_pendiente > 0">
                  <p class="text-muted-foreground">Saldo</p>
                  <p class="font-medium">${{ orden.saldo_pendiente.toFixed(2) }}</p>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle class="text-base">Imagen del diseño</CardTitle>
              </CardHeader>
              <CardContent class="space-y-3">
                <img
                  v-if="orden.imagen_url"
                  :src="orden.imagen_url"
                  alt="Imagen del diseño"
                  class="bg-muted max-h-64 w-full rounded-md object-contain"
                />
                <p v-else class="text-muted-foreground text-sm">Todavía no hay imagen.</p>

                <input
                  ref="inputImagen"
                  type="file"
                  accept="image/png,image/jpeg,image/webp"
                  class="hidden"
                  @change="onArchivoImagen"
                />
                <div class="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    :disabled="subiendoImagen"
                    @click="elegirImagen"
                  >
                    <PhotoIcon class="size-4" />
                    {{ orden.imagen_url ? 'Reemplazar imagen' : 'Subir imagen' }}
                  </Button>
                  <Button
                    v-if="orden.imagen_url"
                    variant="ghost"
                    size="sm"
                    :disabled="subiendoImagen"
                    @click="quitarImagen"
                  >
                    <TrashIcon class="size-4" />
                    Quitar
                  </Button>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle class="text-base">Observaciones</CardTitle>
              </CardHeader>
              <CardContent class="space-y-3">
                <textarea
                  v-model="observaciones"
                  rows="4"
                  placeholder="Detalles del diseño, instrucciones, etc."
                  class="border-input focus-visible:ring-ring w-full rounded-md border bg-transparent p-3 text-sm focus-visible:ring-1 focus-visible:outline-none"
                />
                <div class="flex justify-end">
                  <Button
                    size="sm"
                    :disabled="guardandoObservaciones || !hayCambiosObservaciones"
                    @click="guardarObservaciones"
                  >
                    {{ guardandoObservaciones ? 'Guardando...' : 'Guardar' }}
                  </Button>
                </div>
              </CardContent>
            </Card>

            <Card v-if="orden.envio">
              <CardHeader class="flex flex-row items-center justify-between">
                <CardTitle class="text-base">Ficha de envío</CardTitle>
                <Button size="sm" :disabled="compartiendoFicha" @click="compartirFicha">
                  <ShareIcon class="size-4" />
                  Compartir
                </Button>
              </CardHeader>
              <CardContent>
                <pre class="text-sm whitespace-pre-wrap">{{ fichaEnvioTexto }}</pre>
              </CardContent>
            </Card>
          </div>

          <div class="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle class="text-base">Acciones</CardTitle>
              </CardHeader>
              <CardContent class="space-y-2">
                <Button
                  v-if="orden.estado === 'pendiente'"
                  class="w-full"
                  :disabled="accionando"
                  @click="iniciarProduccion"
                >
                  Iniciar producción
                </Button>
                <Button
                  v-if="orden.estado === 'en_produccion'"
                  class="w-full"
                  :disabled="accionando"
                  @click="marcarListo"
                >
                  Marcar como listo
                </Button>
                <Button
                  v-if="orden.estado === 'listo_para_entregar'"
                  class="w-full"
                  :disabled="accionando"
                  @click="marcarEntregadoMostrador"
                >
                  Marcar como entregado
                </Button>
                <Button
                  v-if="orden.estado === 'listo_para_entregar'"
                  class="w-full"
                  variant="outline"
                  @click="abrirEnvio"
                >
                  <TruckIcon class="size-4" />
                  Enviar a domicilio
                </Button>
                <Button
                  v-if="orden.estado === 'a_domicilio'"
                  class="w-full"
                  :disabled="accionando"
                  @click="marcarEntregado"
                >
                  Marcar como entregado
                </Button>
                <Button
                  v-if="orden.estado === 'entregado' && orden.saldo_pendiente > 0"
                  class="w-full"
                  @click="abrirCobro"
                >
                  <BanknotesIcon class="size-4" />
                  Cobrar ${{ orden.saldo_pendiente.toFixed(2) }}
                </Button>
                <p v-if="orden.estado === 'entregado'" class="text-muted-foreground text-sm">
                  Este trabajo ya fue entregado.
                </p>
              </CardContent>
            </Card>
          </div>
        </div>
      </template>
    </div>

    <Dialog v-model:open="dialogoEnvio">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Enviar a domicilio</DialogTitle>
          <DialogDescription>Datos de quien recibe y de la entrega.</DialogDescription>
        </DialogHeader>

        <div class="space-y-4">
          <Alert v-if="errorEnvio" variant="destructive">
            <AlertDescription>{{ errorEnvio }}</AlertDescription>
          </Alert>

          <div class="space-y-1.5">
            <Label>Nombre de quien recibe</Label>
            <Input v-model="formEnvio.nombre_receptor" />
            <p v-if="erroresEnvio.nombre_receptor" class="text-destructive text-sm">
              {{ erroresEnvio.nombre_receptor }}
            </p>
          </div>

          <div class="space-y-1.5">
            <Label>Teléfono de quien recibe</Label>
            <Input v-model="formEnvio.telefono_receptor" />
            <p v-if="erroresEnvio.telefono_receptor" class="text-destructive text-sm">
              {{ erroresEnvio.telefono_receptor }}
            </p>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div class="space-y-1.5">
              <Label>Fecha de recepción</Label>
              <Input v-model="formEnvio.fecha_recepcion" type="date" />
            </div>
            <div class="space-y-1.5">
              <Label>Hora de recepción</Label>
              <Input v-model="formEnvio.hora_recepcion" type="time" />
              <p v-if="erroresEnvio.hora_recepcion" class="text-destructive text-sm">
                {{ erroresEnvio.hora_recepcion }}
              </p>
            </div>
          </div>

          <div class="space-y-1.5">
            <Label>Tarifa</Label>
            <Select v-model="formEnvio.tarifa">
              <SelectTrigger class="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="a">Tarifa A</SelectItem>
                <SelectItem value="b">Tarifa B</SelectItem>
                <SelectItem value="c">Tarifa C</SelectItem>
              </SelectContent>
            </Select>
            <p v-if="tarifaMonto !== null" class="text-muted-foreground text-sm">
              Monto: ${{ tarifaMonto.toFixed(2) }}
            </p>
          </div>

          <div class="space-y-1.5">
            <Label>Estado del pago del envío</Label>
            <Select v-model="formEnvio.forma_pago">
              <SelectTrigger class="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="por_cobrar">Por cobrar (lo cobra el repartidor)</SelectItem>
                <SelectItem value="prepagado">Prepagado (ya se cobró)</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div v-if="formEnvio.forma_pago === 'prepagado'" class="space-y-1.5">
            <Label>¿A qué cuenta entra el dinero?</Label>
            <CuentaSelect v-model="formEnvio.cuenta_id" />
            <p v-if="erroresEnvio.cuenta_id" class="text-destructive text-sm">
              {{ erroresEnvio.cuenta_id }}
            </p>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" @click="dialogoEnvio = false">Cancelar</Button>
          <Button :disabled="guardandoEnvio" @click="guardarEnvio">
            {{ guardandoEnvio ? 'Guardando...' : 'Guardar envío' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog v-model:open="dialogoCobro">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Cobrar</DialogTitle>
          <DialogDescription>
            El monto entra a la cuenta elegida como un movimiento de ingreso en Tesorería.
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-4">
          <Alert v-if="errorCobro" variant="destructive">
            <AlertDescription>{{ errorCobro }}</AlertDescription>
          </Alert>

          <div class="space-y-1.5">
            <Label>Monto</Label>
            <Input
              :model-value="montoCobro ?? undefined"
              type="number"
              min="0.01"
              step="0.01"
              @update:model-value="(v) => (montoCobro = v === '' ? null : Number(v))"
            />
          </div>
          <div class="space-y-1.5">
            <Label>Fecha</Label>
            <Input v-model="fechaCobro" type="date" />
          </div>
          <div class="space-y-1.5">
            <Label>Cuenta</Label>
            <CuentaSelect v-model="cuentaCobro" />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" :disabled="cobrando" @click="dialogoCobro = false">
            Cancelar
          </Button>
          <Button
            :disabled="cobrando || montoCobro === null || cuentaCobro === null"
            @click="confirmarCobro"
          >
            {{ cobrando ? 'Registrando...' : 'Registrar pago' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
