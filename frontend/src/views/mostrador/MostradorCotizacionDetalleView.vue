<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowLeftIcon,
  BanknotesIcon,
  DocumentTextIcon,
  EnvelopeIcon,
  ShareIcon,
} from '@heroicons/vue/24/outline'
import { useCotizacionesStore, type Cotizacion } from '../../stores/cotizaciones'
import { mensajeDeFalla, mensajeDeFallaDeDescarga } from '../../lib/errors'
import { tipoDePago } from '../../lib/pagoCotizacion'
import type { ArchivoCompartible } from '../../lib/compartir'
import AppLayout from '../../layouts/AppLayout.vue'
import SelectorCuentaMostrador from '../../components/mostrador/SelectorCuentaMostrador.vue'
import { Alert, AlertDescription } from '../../components/ui/alert'
import { Badge } from '../../components/ui/badge'
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
 * El detalle de una cotización en el mostrador (ver 031-mostrador-consulta.md).
 *
 * Consultar, enviar, cobrar y **facturar**, que es el motivo por el que esta pantalla existe. No se
 * edita, no se elimina, no se duplica y no se marca entregada: eso sigue siendo trabajo de
 * computadora.
 */

const ESTADOS: Record<
  string,
  { etiqueta: string; variant: 'secondary' | 'warning' | 'success' | 'default' }
> = {
  borrador: { etiqueta: 'Borrador', variant: 'secondary' },
  enviada: { etiqueta: 'Enviada', variant: 'warning' },
  pagada: { etiqueta: 'Pagada', variant: 'success' },
  producto_entregado: { etiqueta: 'Entregada', variant: 'default' },
}

const route = useRoute()
const router = useRouter()
const cotizaciones = useCotizacionesStore()

const cotizacion = ref<Cotizacion | null>(null)
const cargando = ref(true)
const error = ref<string | null>(null)

const compartiendo = ref(false)
const avisoEnvio = ref<string | null>(null)
/** El PDF ya bajado, listo para el menú de compartir (ver 029, supuesto 78). */
const archivo = ref<ArchivoCompartible | null>(null)

const estado = computed(() =>
  cotizacion.value
    ? (ESTADOS[cotizacion.value.estado] ?? {
        etiqueta: cotizacion.value.estado,
        variant: 'secondary' as const,
      })
    : null,
)

async function cargar() {
  cargando.value = true
  error.value = null

  try {
    cotizacion.value = await cotizaciones.fetchOne(Number(route.params.id))
    correo.value = cotizacion.value.cliente_correo ?? ''
    void prepararEnvio()
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    cargando.value = false
  }
}

void cargar()

// --- Facturar ---

/**
 * El botón se comporta como el del escritorio (ver 008): sin factura, entra a los datos fiscales;
 * con una pendiente, abre esa para reintentar el timbrado sin crear otra; con una timbrada o
 * cancelada, queda apagado. No hay refacturación automática de una cancelada: la vía sigue siendo
 * duplicar la cotización desde la computadora.
 */
const facturaCerrada = computed(
  () =>
    cotizacion.value?.factura_estado === 'timbrada' ||
    cotizacion.value?.factura_estado === 'cancelada',
)

const facturaPorTimbrar = computed(
  () => cotizacion.value?.factura_id !== null && !facturaCerrada.value,
)

const leyendaFactura = computed(() =>
  cotizacion.value?.factura_estado === 'timbrada' ? 'Ya facturada' : 'Su factura fue cancelada',
)

function facturar() {
  if (cotizacion.value === null) return

  if (facturaPorTimbrar.value && cotizacion.value.factura_id !== null) {
    void router.push({
      name: 'mostrador-factura-ver',
      params: { id: cotizacion.value.factura_id },
    })

    return
  }

  void router.push({
    name: 'mostrador-factura',
    query: { cotizacion_id: cotizacion.value.id },
  })
}

// --- Envíos ---

async function prepararEnvio() {
  if (cotizacion.value === null) return

  compartiendo.value = true

  try {
    archivo.value = await cotizaciones.archivoParaWhatsapp(cotizacion.value)
  } catch (err) {
    avisoEnvio.value = await mensajeDeFallaDeDescarga(err)
  } finally {
    compartiendo.value = false
  }
}

async function compartirPorWhatsapp() {
  if (cotizacion.value === null || archivo.value === null) return

  avisoEnvio.value = null

  try {
    const resultado = await cotizaciones.compartirPorWhatsapp(cotizacion.value, archivo.value)

    if (resultado === 'descargado') {
      avisoEnvio.value = 'PDF descargado: adjúntalo en la ventana de WhatsApp que acaba de abrirse.'
    } else if (resultado === 'compartido') {
      avisoEnvio.value = 'Cotización compartida.'
      // Compartir la pasa a "enviada" en el servidor; el detalle tiene que decir lo mismo.
      if (cotizacion.value.estado === 'borrador') cotizacion.value.estado = 'enviada'
    }
  } catch (err) {
    avisoEnvio.value = mensajeDeFalla(err)
  }
}

const correo = ref('')
const enviandoCorreo = ref(false)
const dialogoCorreo = ref(false)

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
    if (cotizacion.value.estado === 'borrador') cotizacion.value.estado = 'enviada'
  } catch (err) {
    avisoEnvio.value = mensajeDeFalla(err)
  } finally {
    enviandoCorreo.value = false
  }
}

// --- Cobro ---

/**
 * La pantalla de cobro de la venta al público, con lo que aquí corresponde: el saldo pendiente ya
 * escrito y bajable, la fecha de hoy y la caja preseleccionada.
 */
const cobrando = ref(false)
const enCobro = ref(false)
const errorPago = ref<string | null>(null)
const monto = ref<number | null>(null)
const fechaPago = ref('')
const cuentaId = ref<number | null>(null)

const saldo = computed(() => cotizacion.value?.saldo_pendiente ?? 0)

/** Una cotización admite **un solo** anticipo, así que con uno ya registrado el monto queda fijo. */
const yaTieneAnticipo = computed(
  () => cotizacion.value?.pagos.some((pago) => pago.tipo === 'anticipo') ?? false,
)

const puedeCobrar = computed(() => saldo.value > 0)

/** El tipo de pago no se le pregunta al usuario: se deduce del monto (ver `lib/pagoCotizacion.ts`). */
const tipoPago = computed(() =>
  tipoDePago(saldo.value, monto.value ?? 0, cotizacion.value?.pagos.length ?? 0),
)

function abrirCobro() {
  monto.value = saldo.value
  fechaPago.value = hoy()
  errorPago.value = null
  enCobro.value = true
}

function hoy(): string {
  const fecha = new Date()
  const mes = String(fecha.getMonth() + 1).padStart(2, '0')
  const dia = String(fecha.getDate()).padStart(2, '0')

  return `${fecha.getFullYear()}-${mes}-${dia}`
}

async function registrarPago() {
  if (cotizacion.value === null || monto.value === null || cuentaId.value === null) return

  cobrando.value = true
  errorPago.value = null

  try {
    cotizacion.value = await cotizaciones.registrarPago(cotizacion.value.id, {
      tipo: tipoPago.value,
      fecha_pago: fechaPago.value,
      // Solo el anticipo lleva monto libre; los otros dos los autocalcula el servidor al saldo.
      monto: tipoPago.value === 'anticipo' ? monto.value : null,
      cuenta_id: cuentaId.value,
    })

    enCobro.value = false
    avisoEnvio.value = 'Pago registrado.'
  } catch (err) {
    errorPago.value = mensajeDeFalla(err)
  } finally {
    cobrando.value = false
  }
}

function fecha(iso: string): string {
  return new Date(iso).toLocaleDateString()
}
</script>

<template>
  <AppLayout mostrador barra>
    <div class="mx-auto max-w-md space-y-4">
      <Button
        variant="ghost"
        size="sm"
        class="-ml-2"
        @click="enCobro ? (enCobro = false) : router.back()"
      >
        <ArrowLeftIcon class="size-4" />
        {{ enCobro ? 'Cotización' : 'Cotizaciones' }}
      </Button>

      <p v-if="cargando" class="text-muted-foreground py-8 text-center">Cargando...</p>

      <Alert v-else-if="error" variant="destructive">
        <AlertDescription class="space-y-2">
          <p>{{ error }}</p>
          <Button type="button" size="sm" variant="outline" @click="cargar">Reintentar</Button>
        </AlertDescription>
      </Alert>

      <!-- La pantalla de cobro, con el saldo a la vista y la caja ya elegida. -->
      <template v-else-if="cotizacion && enCobro">
        <div class="border-border flex items-baseline justify-between border-b pb-3">
          <span class="text-muted-foreground">Saldo pendiente</span>
          <span class="text-3xl font-semibold tabular-nums">${{ saldo.toFixed(2) }}</span>
        </div>

        <div class="space-y-1.5">
          <Label for="monto-pago">Monto a cobrar</Label>
          <Input
            id="monto-pago"
            :model-value="monto ?? undefined"
            type="number"
            inputmode="decimal"
            min="0.01"
            step="0.01"
            :max="saldo"
            :disabled="yaTieneAnticipo"
            class="h-12 text-base"
            @update:model-value="(v) => (monto = v === '' ? null : Number(v))"
          />
          <p class="text-muted-foreground text-sm">
            {{
              yaTieneAnticipo
                ? 'Esta cotización ya tiene un anticipo registrado, así que se cobra el saldo completo.'
                : 'Bájalo para registrar un anticipo.'
            }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label for="fecha-pago">Fecha del pago</Label>
          <Input id="fecha-pago" v-model="fechaPago" type="date" class="h-12 text-base" />
        </div>

        <SelectorCuentaMostrador v-model="cuentaId" />

        <Alert v-if="errorPago" variant="destructive">
          <AlertDescription>{{ errorPago }}</AlertDescription>
        </Alert>

        <Button
          class="h-14 w-full text-base"
          :disabled="cobrando || monto === null || monto <= 0 || cuentaId === null"
          @click="registrarPago"
        >
          {{ cobrando ? 'Registrando...' : 'Registrar pago' }}
        </Button>
      </template>

      <template v-else-if="cotizacion">
        <div class="space-y-1">
          <div class="flex items-center gap-2">
            <h1 class="font-heading text-foreground font-mono text-xl font-semibold">
              #{{ cotizacion.folio }}
            </h1>
            <Badge v-if="estado" :variant="estado.variant">{{ estado.etiqueta }}</Badge>
          </div>
          <p class="text-foreground text-lg font-medium break-words">
            {{ cotizacion.cliente_razon_social }}
          </p>
          <p class="text-muted-foreground font-mono">{{ cotizacion.cliente_rfc }}</p>
          <p class="text-muted-foreground text-sm">{{ fecha(cotizacion.created_at) }}</p>
        </div>

        <ul class="border-border divide-border divide-y rounded-lg border">
          <li
            v-for="linea in cotizacion.lineas"
            :key="linea.id"
            class="flex items-start justify-between gap-3 p-3"
          >
            <div class="min-w-0 flex-1">
              <p class="text-foreground font-medium break-words">{{ linea.descripcion }}</p>
              <p class="text-muted-foreground text-sm">
                {{ linea.cantidad }} × ${{ linea.precio_unitario.toFixed(2) }}
              </p>
            </div>
            <span class="shrink-0 font-semibold tabular-nums">
              ${{ linea.importe.toFixed(2) }}
            </span>
          </li>
        </ul>

        <div class="border-border space-y-1 border-t pt-3">
          <div class="flex items-baseline justify-between">
            <span class="text-muted-foreground">Total</span>
            <span class="text-3xl font-semibold tabular-nums">
              ${{ cotizacion.total.toFixed(2) }}
            </span>
          </div>
          <div
            v-if="cotizacion.total_pagado > 0"
            class="text-muted-foreground flex items-baseline justify-between text-sm"
          >
            <span>Pagado ${{ cotizacion.total_pagado.toFixed(2) }}</span>
            <span>Saldo ${{ cotizacion.saldo_pendiente.toFixed(2) }}</span>
          </div>
        </div>

        <Alert v-if="avisoEnvio">
          <AlertDescription>{{ avisoEnvio }}</AlertDescription>
        </Alert>

        <!-- Facturar: el motivo por el que esta pantalla existe. -->
        <template v-if="facturaCerrada">
          <Button class="h-14 w-full text-base" disabled>
            <DocumentTextIcon class="size-5" />
            {{ leyendaFactura }}
          </Button>
          <Button
            v-if="cotizacion.factura_id"
            variant="outline"
            class="h-12 w-full"
            @click="
              router.push({
                name: 'mostrador-factura-ver',
                params: { id: cotizacion.factura_id },
              })
            "
          >
            Ver su factura
          </Button>
        </template>

        <Button v-else class="h-14 w-full text-base" @click="facturar">
          <DocumentTextIcon class="size-5" />
          {{ facturaPorTimbrar ? 'Reintentar su factura' : 'Facturar' }}
        </Button>

        <Button
          variant="outline"
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

        <Button
          v-if="puedeCobrar"
          variant="outline"
          class="h-14 w-full text-base"
          @click="abrirCobro"
        >
          <BanknotesIcon class="size-5" />
          Registrar pago
        </Button>
      </template>
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
          <Label for="correo-cotizacion-detalle">Correo</Label>
          <Input
            id="correo-cotizacion-detalle"
            v-model="correo"
            inputmode="email"
            class="h-12 text-base"
          />
        </div>

        <DialogFooter>
          <Button variant="outline" @click="dialogoCorreo = false">Cancelar</Button>
          <Button :disabled="enviandoCorreo || correo.trim() === ''" @click="enviarPorCorreo">
            {{ enviandoCorreo ? 'Enviando...' : 'Enviar' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
