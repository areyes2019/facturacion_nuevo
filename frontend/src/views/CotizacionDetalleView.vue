<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  ArrowDownTrayIcon,
  EnvelopeIcon,
  BanknotesIcon,
  TruckIcon,
  DocumentDuplicateIcon,
  DocumentTextIcon,
  QrCodeIcon,
  ReceiptPercentIcon,
  ShareIcon,
  TrashIcon,
  WrenchScrewdriverIcon,
  PrinterIcon,
} from '@heroicons/vue/24/outline'
import {
  useCotizacionesStore,
  type Cotizacion,
  type CotizacionPago,
  type EstadoCotizacion,
  type TipoPagoCotizacion,
} from '../stores/cotizaciones'
import { useOrdenesTrabajoStore, type EnvioPayload } from '../stores/ordenesTrabajo'
import { extractErrorMessage, mensajeDeFallaDeDescarga } from '../lib/errors'
import FormularioEnvio from '../components/envio/FormularioEnvio.vue'
import FichaEnvio from '../components/envio/FichaEnvio.vue'
import { compartirArchivo, type ArchivoCompartible } from '../lib/compartir'
import { caducaPronto, fechaLegible, textoCaducidad } from '../lib/caducidadCotizacion'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Badge } from '../components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '../components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '../components/ui/dialog'
import CuentaSelect from '../components/CuentaSelect.vue'
import AvisoEmisorIncompleto from '../components/AvisoEmisorIncompleto.vue'

const route = useRoute()
const router = useRouter()
const cotizacionesStore = useCotizacionesStore()
const ordenesTrabajo = useOrdenesTrabajoStore()

const cotizacion = ref<Cotizacion | null>(null)
const cargando = ref(true)
const errorGeneral = ref<string | null>(null)
const creandoOrden = ref(false)

const cotizacionId = computed(() => Number(route.params.id))

/** Solo con algún pago registrado: mismo requisito que valida el backend al crear la orden. */
const tienePagos = computed(() => (cotizacion.value?.pagos.length ?? 0) > 0)

// ---------------------------------------------------------------------------
// Envío directo a domicilio (solo clientes distribuidores, sin Orden de Trabajo — ver
// 041-envio-domicilio-direccion-y-distribuidor.md)
// ---------------------------------------------------------------------------
const puedeEnviarDirecto = computed(
  () => (cotizacion.value?.cliente_es_distribuidor ?? false) && tienePagos.value,
)

const dialogoEnvioDirecto = ref(false)

async function onGuardarEnvioDirecto(payload: EnvioPayload) {
  if (!cotizacion.value) return
  cotizacion.value = await cotizacionesStore.crearEnvio(cotizacion.value.id, payload)
}

async function onMarcarEnvioEntregado() {
  if (!cotizacion.value) return
  cotizacion.value = await cotizacionesStore.marcarEnvioEntregado(cotizacion.value.id)
}

const lineasFichaEnvioDirecto = computed(() => [
  `Cliente: ${cotizacion.value?.cliente_razon_social ?? ''}`,
  `Teléfono: ${cotizacion.value?.cliente_telefono ?? ''}`,
  `Cotización: ${cotizacion.value?.folio ?? ''}`,
])

const importePendienteEnvioDirecto = computed(() => {
  if (!cotizacion.value?.envio) return 0
  return cotizacion.value.envio.forma_pago === 'por_cobrar'
    ? cotizacion.value.saldo_pendiente + cotizacion.value.envio.monto
    : cotizacion.value.saldo_pendiente
})

/**
 * Abre Producción para esta cotización: crea la Orden de Trabajo si todavía no existe y navega a
 * su detalle (ver 038-produccion-ordenes-trabajo.md).
 */
async function irAProduccion() {
  if (!cotizacion.value) return

  if (cotizacion.value.orden_trabajo_id) {
    router.push({ name: 'produccion-detalle', params: { id: cotizacion.value.orden_trabajo_id } })
    return
  }

  creandoOrden.value = true
  errorGeneral.value = null
  try {
    const orden = await ordenesTrabajo.create('cotizacion', cotizacion.value.id)
    router.push({ name: 'produccion-detalle', params: { id: orden.id } })
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    creandoOrden.value = false
  }
}

function estadoVariant(estado: EstadoCotizacion) {
  return {
    borrador: 'secondary',
    enviada: 'warning',
    pagada: 'success',
    producto_entregado: 'default',
  }[estado] as 'secondary' | 'warning' | 'success' | 'default'
}

async function cargar() {
  cargando.value = true
  try {
    cotizacion.value = await cotizacionesStore.fetchOne(cotizacionId.value)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

// Una cotización admite como máximo un anticipo (ver 008-cotizaciones.md): mientras no exista,
// se puede registrar "anticipo" o "pago total"; una vez que existe, solo queda "saldo" (y solo
// mientras haya saldo pendiente).
const tieneAnticipo = computed(
  () => cotizacion.value?.pagos.some((pago) => pago.tipo === 'anticipo') ?? false,
)

// Botón "Facturar": ahora una cotización puede tener varias facturas, cada una por un monto
// parcial (ver 043-facturas-parciales-cotizacion.md). El estado se calcula a partir de la lista de
// facturas y del saldo pendiente por facturar, no de un solo `factura_id`.
const facturaPendiente = computed(
  () => cotizacion.value?.facturas.find((f) => f.estado === 'pendiente') ?? null,
)
const facturarEstado = computed<'sin-factura' | 'disponible' | 'pendiente' | 'agotado'>(() => {
  if (facturaPendiente.value) return 'pendiente'
  if ((cotizacion.value?.saldo_pendiente_facturar ?? 0) <= 0) return 'agotado'
  return (cotizacion.value?.facturas.length ?? 0) === 0 ? 'sin-factura' : 'disponible'
})

function onFacturar() {
  if (!cotizacion.value) return

  if (facturarEstado.value === 'pendiente') {
    router.push({ name: 'facturas-editar', params: { id: facturaPendiente.value!.id } })
  } else if (facturarEstado.value === 'sin-factura' || facturarEstado.value === 'disponible') {
    router.push({ name: 'facturas-crear', query: { cotizacion_id: String(cotizacion.value.id) } })
  }
}

function estadoFacturaVariant(estado: string) {
  return {
    timbrada: 'success',
    pendiente: 'warning',
    cancelada: 'destructive',
    borrador: 'secondary',
  }[estado] as 'success' | 'warning' | 'destructive' | 'secondary'
}

/**
 * Enviar por correo o por WhatsApp. El correo sale del servidor con el PDF adjunto; el WhatsApp lo
 * comparte este mismo navegador, que descarga el PDF y abre WhatsApp con el mensaje escrito (ver
 * 029-pwa-mostrador.md). Antes lo mandaba Twilio y, sin credenciales configuradas, el botón
 * respondía con un error del servidor.
 */
const mostrarEnviar = ref(false)
const canalEnvio = ref<'correo' | 'whatsapp'>('correo')
const destinatarios = ref('')
const enviando = ref(false)
const errorEnviar = ref<string | null>(null)
const enviadoOk = ref(false)
const archivo = ref<ArchivoCompartible | null>(null)

function abrirEnviar() {
  canalEnvio.value = 'correo'
  destinatarios.value = cotizacion.value?.cliente_correo ?? ''
  errorEnviar.value = null
  enviadoOk.value = false
  archivo.value = null
  mostrarEnviar.value = true
}

/**
 * El PDF se baja al elegir el canal, no al apretar "Enviar": el menú de compartir solo se abre
 * mientras el gesto del usuario sigue vivo, y una descarga en medio lo agota (ver
 * 029-pwa-mostrador.md, supuesto 78).
 */
watch([canalEnvio, mostrarEnviar], async ([canal, abierto]) => {
  if (!abierto || canal !== 'whatsapp' || archivo.value !== null || cotizacion.value === null) {
    return
  }

  enviando.value = true
  errorEnviar.value = null

  try {
    archivo.value = await cotizacionesStore.archivoParaWhatsapp(cotizacion.value)
  } catch (err) {
    errorEnviar.value = await mensajeDeFallaDeDescarga(err)
  } finally {
    enviando.value = false
  }
})

async function confirmarEnviar() {
  if (!cotizacion.value) return

  enviando.value = true
  errorEnviar.value = null
  try {
    if (canalEnvio.value === 'correo') {
      const lista = destinatarios.value
        .split(',')
        .map((d) => d.trim())
        .filter((d) => d.length > 0)
      await cotizacionesStore.enviar(cotizacion.value.id, { canal: 'correo', destinatarios: lista })
      enviadoOk.value = true
    } else {
      if (archivo.value === null) return

      const resultado = await cotizacionesStore.compartirPorWhatsapp(
        cotizacion.value,
        archivo.value,
      )
      enviadoOk.value = resultado !== 'cancelado'
    }

    await cargar()
  } catch (err) {
    errorEnviar.value = extractErrorMessage(err)
  } finally {
    enviando.value = false
  }
}

// Descarga de PDF.
const descargandoPdf = ref(false)
const errorDescarga = ref<string | null>(null)

async function onDescargarPdf() {
  if (!cotizacion.value) return
  descargandoPdf.value = true
  errorDescarga.value = null
  try {
    await cotizacionesStore.descargarPdf(cotizacion.value)
  } catch (err) {
    errorDescarga.value = extractErrorMessage(err)
  } finally {
    descargandoPdf.value = false
  }
}

// Registro de pagos. El pago entra a una cuenta de Tesorería (no a una forma de pago del catálogo
// SAT, que en una cotización nunca se timbraba) y genera ahí un movimiento de ingreso automático
// (ver 010-tesoreria.md).
const mostrarPago = ref(false)
const tipoPago = ref<TipoPagoCotizacion>('anticipo')
const fechaPago = ref('')
const montoPago = ref<number | null>(null)
const cuentaPago = ref<number | null>(null)
const registrandoPago = ref(false)
const errorPago = ref<string | null>(null)

function abrirPago(tipo: TipoPagoCotizacion) {
  tipoPago.value = tipo
  fechaPago.value = new Date().toISOString().slice(0, 10)
  montoPago.value = null
  cuentaPago.value = null
  errorPago.value = null
  mostrarPago.value = true
}

async function confirmarPago() {
  if (!cotizacion.value || !cuentaPago.value) return
  if (tipoPago.value === 'anticipo' && !montoPago.value) return

  registrandoPago.value = true
  errorPago.value = null
  try {
    cotizacion.value = await cotizacionesStore.registrarPago(cotizacion.value.id, {
      tipo: tipoPago.value,
      fecha_pago: fechaPago.value,
      // "saldo"/"pago_total" siempre los autocalcula el backend como el saldo pendiente; solo
      // "anticipo" manda un monto libre (ver 008-cotizaciones.md).
      monto: tipoPago.value === 'anticipo' ? montoPago.value : null,
      cuenta_id: cuentaPago.value,
    })
    mostrarPago.value = false
  } catch (err) {
    errorPago.value = extractErrorMessage(err)
  } finally {
    registrandoPago.value = false
  }
}

/**
 * Recibo de un pago concreto (ver 040-recibo-anticipo-cotizacion.md): igual que "Compartir QR",
 * baja el PDF y lo entrega de inmediato al menú de compartir, con texto de respaldo para el
 * escritorio. El estado de carga es por fila (el `id` del pago que se está generando), para no
 * deshabilitar el resto de los recibos mientras uno se genera.
 */
const generandoReciboId = ref<number | null>(null)
const errorRecibo = ref<string | null>(null)

function etiquetaTipoPago(tipo: TipoPagoCotizacion): string {
  return tipo === 'anticipo' ? 'Anticipo' : tipo === 'saldo' ? 'Saldo' : 'Pago total'
}

async function compartirRecibo(pago: CotizacionPago) {
  if (!cotizacion.value) return

  generandoReciboId.value = pago.id
  errorRecibo.value = null

  try {
    const blob = await cotizacionesStore.reciboPagoBlob(cotizacion.value.id, pago.id)
    const texto = `Recibo de ${etiquetaTipoPago(pago.tipo)} de la cotización ${cotizacion.value.folio} (${cotizacion.value.cliente_razon_social}): $${pago.monto.toFixed(2)}.`

    await compartirArchivo(
      blob,
      `recibo-cotizacion-${cotizacion.value.folio}-${pago.tipo}.pdf`,
      texto,
    )
  } catch {
    errorRecibo.value = 'No se pudo generar el recibo.'
  } finally {
    generandoReciboId.value = null
  }
}

// Eliminación de pagos: solo el más reciente (criterio LIFO), y no si el producto ya se entregó.
const pagoAEliminar = ref<CotizacionPago | null>(null)
const eliminandoPago = ref(false)
const errorEliminarPago = ref<string | null>(null)

const pagoMasReciente = computed(() => {
  const pagos = cotizacion.value?.pagos ?? []
  return pagos.length > 0 ? pagos[pagos.length - 1] : null
})

function puedeEliminarPago(pago: CotizacionPago) {
  return pago.id === pagoMasReciente.value?.id && cotizacion.value?.estado !== 'producto_entregado'
}

async function confirmarEliminarPago() {
  if (!cotizacion.value || !pagoAEliminar.value) return

  eliminandoPago.value = true
  errorEliminarPago.value = null
  try {
    await cotizacionesStore.eliminarPago(cotizacion.value.id, pagoAEliminar.value.id)
    pagoAEliminar.value = null
    await cargar()
  } catch (err) {
    errorEliminarPago.value = extractErrorMessage(err)
  } finally {
    eliminandoPago.value = false
  }
}

// Marcar como entregado.
const entregando = ref(false)
async function onEntregar() {
  if (!cotizacion.value) return
  entregando.value = true
  try {
    const resultado = await cotizacionesStore.entregar(cotizacion.value.id)
    cotizacion.value = resultado.cotizacion
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    entregando.value = false
  }
}

// Eliminar la cotización completa. El backend ya resolvió si se puede (borrador/enviada, sin pagos
// y sin factura): aquí solo se confirma, porque el borrado es físico y definitivo.
const mostrarEliminar = ref(false)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

async function confirmarEliminar() {
  if (!cotizacion.value) return
  eliminando.value = true
  errorEliminar.value = null
  try {
    await cotizacionesStore.remove(cotizacion.value.id)
    await router.push({ name: 'cotizaciones' })
  } catch (err) {
    errorEliminar.value = extractErrorMessage(err)
  } finally {
    eliminando.value = false
  }
}

// Duplicar.
const duplicando = ref(false)
async function onDuplicar() {
  if (!cotizacion.value) return
  duplicando.value = true
  try {
    const copia = await cotizacionesStore.duplicar(cotizacion.value.id)
    await router.push({ name: 'cotizaciones-detalle', params: { id: copia.id } })
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    duplicando.value = false
  }
}

/**
 * "Compartir QR" (ver 039-qr-conductor-produccion.md): el mismo código que ya se dibuja en pantalla
 * y en el PDF, solo que en grande y listo para el panel nativo de compartir, sin bajarlo a mano.
 */
const mostrarCompartirQr = ref(false)
const compartiendoQr = ref(false)
const errorCompartirQr = ref<string | null>(null)

function abrirCompartirQr() {
  errorCompartirQr.value = null
  mostrarCompartirQr.value = true
}

async function compartirQr() {
  if (!cotizacion.value?.qr_entrega) return

  compartiendoQr.value = true
  errorCompartirQr.value = null

  try {
    const blob = await (await fetch(cotizacion.value.qr_entrega)).blob()
    const texto = `QR de seguimiento de la cotización ${cotizacion.value.folio} (${cotizacion.value.cliente_razon_social}).`

    await compartirArchivo(blob, `QR-cotizacion-${cotizacion.value.folio}.png`, texto)
  } catch {
    errorCompartirQr.value = 'No se pudo compartir el QR.'
  } finally {
    compartiendoQr.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-4xl space-y-4">
      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <template v-if="!cargando && cotizacion">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 class="font-heading text-foreground text-xl font-semibold">
              Cotización {{ cotizacion.folio }}
            </h1>
            <p class="text-muted-foreground text-sm">{{ cotizacion.cliente_razon_social }}</p>
            <!-- Valor congelado: el que tenía el cliente ese día, que puede no coincidir ni con el
                 vigente ni con el de cada línea si se editaron (ver 015). -->
            <p
              v-if="cotizacion.descuento_cliente_porcentaje > 0"
              class="text-muted-foreground text-sm"
            >
              Descuento de cliente al cotizar:
              <strong>{{ cotizacion.descuento_cliente_porcentaje }}%</strong>
            </p>
          </div>
          <Badge :variant="estadoVariant(cotizacion.estado)">
            {{ cotizacion.estado }}
          </Badge>
        </div>

        <AvisoEmisorIncompleto />

        <div class="flex flex-wrap gap-2">
          <Button variant="outline" @click="abrirEnviar">
            <EnvelopeIcon class="size-4" />
            Enviar
          </Button>
          <Button variant="outline" :disabled="descargandoPdf" @click="onDescargarPdf">
            <ArrowDownTrayIcon class="size-4" />
            {{ descargandoPdf ? 'Descargando...' : 'Descargar PDF' }}
          </Button>
          <template v-if="cotizacion.estado === 'enviada'">
            <template v-if="!tieneAnticipo">
              <Button variant="outline" @click="abrirPago('anticipo')">
                <BanknotesIcon class="size-4" />
                Registrar anticipo
              </Button>
              <Button variant="outline" @click="abrirPago('pago_total')">
                <BanknotesIcon class="size-4" />
                Pago total
              </Button>
            </template>
            <Button
              v-if="tieneAnticipo && cotizacion.saldo_pendiente > 0"
              variant="outline"
              @click="abrirPago('saldo')"
            >
              <BanknotesIcon class="size-4" />
              Registrar saldo
            </Button>
          </template>
          <Button
            v-if="cotizacion.estado === 'pagada'"
            variant="outline"
            :disabled="entregando"
            @click="onEntregar"
          >
            <TruckIcon class="size-4" />
            Marcar como entregado
          </Button>
          <Button variant="outline" :disabled="duplicando" @click="onDuplicar">
            <DocumentDuplicateIcon class="size-4" />
            Duplicar
          </Button>
          <!-- QR de entrega (ver 038): igual que la etiqueta de Pedido, se genera desde que la
               cotización existe, sin esperar a que tenga pagos. -->
          <Button as-child variant="outline">
            <RouterLink
              :to="{ name: 'cotizaciones-etiqueta', params: { id: cotizacionId } }"
              target="_blank"
            >
              <PrinterIcon class="size-4" />
              Imprimir etiqueta
            </RouterLink>
          </Button>
          <!-- Compartir QR (ver 039-qr-conductor-produccion.md): mismo QR de entrega, en grande y
               listo para compartir sin bajar la imagen a mano. -->
          <Button variant="outline" @click="abrirCompartirQr">
            <QrCodeIcon class="size-4" />
            Compartir QR
          </Button>
          <!-- Producción (ver 038): solo si la cotización ya tiene algún pago, mismo requisito que
               el backend valida al crear la orden. -->
          <Button
            v-if="tienePagos"
            variant="outline"
            :disabled="creandoOrden"
            @click="irAProduccion"
          >
            <WrenchScrewdriverIcon class="size-4" />
            {{
              cotizacion?.orden_trabajo_id
                ? 'Ver Orden de Trabajo'
                : creandoOrden
                  ? 'Creando...'
                  : 'Crear Orden de Trabajo'
            }}
          </Button>
          <!-- Envío directo a domicilio: solo clientes distribuidores, sin pasar por Producción
               (ver 041-envio-domicilio-direccion-y-distribuidor.md). -->
          <Button
            v-if="puedeEnviarDirecto && !cotizacion.envio"
            variant="outline"
            @click="dialogoEnvioDirecto = true"
          >
            <TruckIcon class="size-4" />
            Enviar a domicilio
          </Button>
          <Button variant="outline" :disabled="facturarEstado === 'agotado'" @click="onFacturar">
            <DocumentTextIcon class="size-4" />
            {{
              facturarEstado === 'sin-factura'
                ? 'Facturar'
                : facturarEstado === 'disponible'
                  ? 'Facturar saldo restante'
                  : facturarEstado === 'pendiente'
                    ? 'Reintentar factura'
                    : 'Facturada'
            }}
          </Button>
          <Button
            v-if="cotizacion.puede_eliminarse"
            variant="destructive"
            @click="mostrarEliminar = true"
          >
            <TrashIcon class="size-4" />
            Eliminar
          </Button>
        </div>

        <!-- El borrado por inactividad es físico: avisarlo con días de anticipación es la única
             forma de rescatar una cotización que sí importaba (ver 008-cotizaciones.md). -->
        <Alert v-if="caducaPronto(cotizacion.caduca_el)" variant="warning">
          <AlertDescription>
            Sin movimiento desde el {{ fechaLegible(cotizacion.updated_at) }}. Se eliminará
            automáticamente el {{ fechaLegible(cotizacion.caduca_el!) }} ({{
              textoCaducidad(cotizacion.caduca_el).toLowerCase()
            }}). Editarla o reenviarla reinicia el plazo.
          </AlertDescription>
        </Alert>

        <Alert v-if="errorDescarga" variant="destructive">
          <AlertDescription>{{ errorDescarga }}</AlertDescription>
        </Alert>

        <Alert v-if="errorRecibo" variant="destructive">
          <AlertDescription>{{ errorRecibo }}</AlertDescription>
        </Alert>

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Pagos</CardTitle>
          </CardHeader>
          <CardContent class="space-y-2 text-sm">
            <div class="flex justify-between">
              <span>Total pagado</span><span>${{ cotizacion.total_pagado.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Saldo pendiente</span><span>${{ cotizacion.saldo_pendiente.toFixed(2) }}</span>
            </div>
            <Table v-if="cotizacion.pagos.length > 0">
              <TableHeader>
                <TableRow>
                  <TableHead>Tipo</TableHead>
                  <TableHead>Fecha</TableHead>
                  <TableHead class="text-right">Monto</TableHead>
                  <TableHead>Cuenta</TableHead>
                  <TableHead class="text-right">Acciones</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="pago in cotizacion.pagos" :key="pago.id">
                  <TableCell>{{ pago.tipo }}</TableCell>
                  <TableCell>{{ pago.fecha_pago }}</TableCell>
                  <TableCell class="text-right">${{ pago.monto.toFixed(2) }}</TableCell>
                  <TableCell>{{ pago.cuenta_nombre ?? '—' }}</TableCell>
                  <TableCell class="flex justify-end gap-2 text-right">
                    <!-- Recibo (040): disponible para cualquier pago del historial, no solo el más
                         reciente — a diferencia de "Eliminar", que sí depende de esa regla. -->
                    <Button
                      variant="outline"
                      size="sm"
                      :disabled="generandoReciboId === pago.id"
                      @click="compartirRecibo(pago)"
                    >
                      <ReceiptPercentIcon class="size-4" />
                      {{ generandoReciboId === pago.id ? 'Generando...' : 'Recibo' }}
                    </Button>
                    <!-- Solo el pago más reciente se puede eliminar (criterio LIFO): el monto de
                         saldo/pago total se autocalcula a partir de los previos. -->
                    <Button
                      v-if="puedeEliminarPago(pago)"
                      variant="outline"
                      size="sm"
                      @click="pagoAEliminar = pago"
                    >
                      Eliminar
                    </Button>
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <!-- Una cotización puede tener varias facturas, cada una por un monto parcial (ver
             043-facturas-parciales-cotizacion.md). -->
        <Card v-if="cotizacion.facturas.length > 0">
          <CardHeader>
            <CardTitle class="text-base">Facturas</CardTitle>
          </CardHeader>
          <CardContent class="space-y-2 text-sm">
            <div class="flex justify-between">
              <span>Total facturado</span><span>${{ cotizacion.total_facturado.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Saldo pendiente por facturar</span
              ><span>${{ cotizacion.saldo_pendiente_facturar.toFixed(2) }}</span>
            </div>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Folio</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead class="text-right">Total</TableHead>
                  <TableHead class="text-right">Acciones</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="factura in cotizacion.facturas" :key="factura.id">
                  <TableCell>{{ factura.folio }}</TableCell>
                  <TableCell>
                    <Badge :variant="estadoFacturaVariant(factura.estado)">{{
                      factura.estado
                    }}</Badge>
                  </TableCell>
                  <TableCell class="text-right">${{ factura.total.toFixed(2) }}</TableCell>
                  <TableCell class="text-right">
                    <Button as-child variant="outline" size="sm">
                      <RouterLink :to="{ name: 'facturas-detalle', params: { id: factura.id } }">
                        Ver
                      </RouterLink>
                    </Button>
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <FichaEnvio
          v-if="cotizacion.envio"
          :envio="cotizacion.envio"
          :lineas="lineasFichaEnvioDirecto"
          :importe-pendiente="importePendienteEnvioDirecto"
          :on-marcar-entregado="onMarcarEnvioEntregado"
        />

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Artículos</CardTitle>
          </CardHeader>
          <CardContent class="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Cantidad</TableHead>
                  <TableHead>Descripción</TableHead>
                  <TableHead>Modelo</TableHead>
                  <TableHead class="text-right">P. unitario</TableHead>
                  <TableHead>IVA</TableHead>
                  <TableHead class="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="linea in cotizacion.lineas" :key="linea.id">
                  <TableCell>{{ linea.cantidad }}</TableCell>
                  <TableCell>{{ linea.descripcion }}</TableCell>
                  <TableCell>{{ linea.modelo }}</TableCell>
                  <TableCell class="text-right">${{ linea.precio_unitario.toFixed(2) }}</TableCell>
                  <TableCell>{{
                    linea.tasa_iva === 'exento' ? 'Exento' : linea.tasa_iva + '%'
                  }}</TableCell>
                  <TableCell class="text-right">${{ linea.importe.toFixed(2) }}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="ml-auto max-w-xs space-y-1 pt-6 text-sm">
            <div class="flex justify-between">
              <span>Subtotal</span><span>${{ cotizacion.subtotal.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Descuento</span><span>${{ cotizacion.total_descuento.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>IVA 16%</span><span>${{ cotizacion.total_iva_16.toFixed(2) }}</span>
            </div>
            <div v-if="cotizacion.ajuste_al_peso > 0" class="flex justify-between">
              <span>Ajuste al peso</span><span>${{ cotizacion.ajuste_al_peso.toFixed(2) }}</span>
            </div>
            <div class="text-foreground flex justify-between border-t pt-1 text-base font-semibold">
              <span>Total</span><span>${{ cotizacion.total.toFixed(2) }}</span>
            </div>
          </CardContent>
        </Card>

        <div>
          <Button variant="outline" @click="router.push({ name: 'cotizaciones' })"
            >Volver al listado</Button
          >
        </div>
      </template>

      <Dialog :open="mostrarEnviar" @update:open="(v) => (mostrarEnviar = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Enviar cotización</DialogTitle>
            <DialogDescription>Elige el canal de envío.</DialogDescription>
          </DialogHeader>
          <div class="min-w-0 space-y-4">
            <div class="space-y-1.5">
              <Label>Canal</Label>
              <select
                v-model="canalEnvio"
                class="border-input h-9 w-full rounded-md border bg-transparent px-2 text-sm"
              >
                <option value="correo">Correo</option>
                <option value="whatsapp">WhatsApp</option>
              </select>
            </div>
            <div v-if="canalEnvio === 'correo'" class="space-y-1.5">
              <Label for="destinatarios">Destinatarios</Label>
              <Input
                id="destinatarios"
                v-model="destinatarios"
                placeholder="correo1@ejemplo.com, correo2@ejemplo.com"
              />
            </div>
            <p v-else class="text-muted-foreground text-sm">
              Se descarga el PDF y se abre WhatsApp con el mensaje escrito: el contacto lo eliges
              ahí. No hace falta capturar el teléfono.
            </p>
          </div>
          <Alert v-if="errorEnviar" variant="destructive">
            <AlertDescription>{{ errorEnviar }}</AlertDescription>
          </Alert>
          <Alert v-if="enviadoOk" variant="success">
            <AlertDescription>
              {{
                canalEnvio === 'correo'
                  ? 'Cotización enviada correctamente.'
                  : 'PDF listo y mensaje copiado: adjúntalo en WhatsApp y pega el texto.'
              }}
            </AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="enviando" @click="mostrarEnviar = false"
              >Cerrar</Button
            >
            <Button :disabled="enviando" @click="confirmarEnviar">
              {{ enviando ? 'Enviando...' : 'Enviar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarPago" @update:open="(v) => (mostrarPago = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Registrar pago</DialogTitle>
            <DialogDescription>
              {{
                tipoPago === 'anticipo' ? 'Anticipo' : tipoPago === 'saldo' ? 'Saldo' : 'Pago total'
              }}
            </DialogDescription>
          </DialogHeader>
          <div class="min-w-0 space-y-4">
            <div class="space-y-1.5">
              <Label for="fecha_pago">Fecha de pago</Label>
              <Input id="fecha_pago" v-model="fechaPago" type="date" />
            </div>
            <div v-if="tipoPago === 'anticipo'" class="space-y-1.5">
              <Label for="monto">Monto</Label>
              <Input
                id="monto"
                :model-value="montoPago ?? undefined"
                type="number"
                min="0.01"
                step="0.01"
                @update:model-value="(v) => (montoPago = v === '' ? null : Number(v))"
              />
            </div>
            <p v-else class="text-sm">
              Se registrará un pago de
              <strong>${{ (cotizacion?.saldo_pendiente ?? 0).toFixed(2) }}</strong>
              (saldo pendiente).
            </p>
            <div class="space-y-1.5">
              <Label>Cuenta</Label>
              <CuentaSelect v-model="cuentaPago" />
            </div>
          </div>
          <Alert v-if="errorPago" variant="destructive">
            <AlertDescription>{{ errorPago }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="registrandoPago" @click="mostrarPago = false">
              Cerrar
            </Button>
            <Button :disabled="registrandoPago" @click="confirmarPago">
              {{ registrandoPago ? 'Registrando...' : 'Registrar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarCompartirQr" @update:open="(v) => (mostrarCompartirQr = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Compartir QR</DialogTitle>
            <DialogDescription>
              El cliente escanea este código para dar seguimiento a su trabajo.
            </DialogDescription>
          </DialogHeader>

          <div class="space-y-4 text-center">
            <img
              v-if="cotizacion?.qr_entrega"
              :src="cotizacion.qr_entrega"
              alt="QR de la cotización"
              class="mx-auto size-56"
            />
            <div class="space-y-1 text-left text-sm">
              <p>
                <span class="text-muted-foreground">Cliente:</span>
                {{ cotizacion?.cliente_razon_social }}
              </p>
              <p><span class="text-muted-foreground">Cotización:</span> {{ cotizacion?.folio }}</p>
              <p>
                <span class="text-muted-foreground">
                  {{ (cotizacion?.saldo_pendiente ?? 0) > 0 ? 'Saldo pendiente' : 'Total' }}:
                </span>
                ${{
                  ((cotizacion?.saldo_pendiente ?? 0) > 0
                    ? cotizacion?.saldo_pendiente
                    : cotizacion?.total
                  )?.toFixed(2)
                }}
              </p>
            </div>
          </div>

          <Alert v-if="errorCompartirQr" variant="destructive">
            <AlertDescription>{{ errorCompartirQr }}</AlertDescription>
          </Alert>

          <DialogFooter>
            <Button variant="outline" @click="mostrarCompartirQr = false">Cerrar</Button>
            <Button :disabled="compartiendoQr" @click="compartirQr">
              <ShareIcon class="size-4" />
              {{ compartiendoQr ? 'Compartiendo...' : 'Compartir' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="pagoAEliminar !== null" @update:open="(v) => !v && (pagoAEliminar = null)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar pago</DialogTitle>
            <DialogDescription>
              Se eliminará el pago de ${{ (pagoAEliminar?.monto ?? 0).toFixed(2) }} y su movimiento
              de ingreso en Tesorería, y el saldo de la cuenta se recalculará. Si la cotización
              estaba pagada y ya no alcanza su total, volverá a "enviada".
            </DialogDescription>
          </DialogHeader>
          <Alert v-if="errorEliminarPago" variant="destructive">
            <AlertDescription>{{ errorEliminarPago }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="eliminandoPago" @click="pagoAEliminar = null">
              Cancelar
            </Button>
            <Button variant="destructive" :disabled="eliminandoPago" @click="confirmarEliminarPago">
              Eliminar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <FormularioEnvio v-model:open="dialogoEnvioDirecto" :guardar="onGuardarEnvioDirecto" />

      <Dialog :open="mostrarEliminar" @update:open="(v) => (mostrarEliminar = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar cotización</DialogTitle>
            <DialogDescription>
              Se eliminará la cotización {{ cotizacion?.folio }} con todas sus líneas. Es
              definitivo: no hay papelera de la que recuperarla.
            </DialogDescription>
          </DialogHeader>
          <Alert v-if="errorEliminar" variant="destructive">
            <AlertDescription>{{ errorEliminar }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="eliminando" @click="mostrarEliminar = false">
              Cancelar
            </Button>
            <Button variant="destructive" :disabled="eliminando" @click="confirmarEliminar">
              {{ eliminando ? 'Eliminando...' : 'Eliminar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
