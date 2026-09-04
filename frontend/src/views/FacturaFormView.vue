<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFacturasStore, type FacturaPayload, type TipoDescuento } from '../stores/facturas'
import { useCotizacionesStore, type Cotizacion } from '../stores/cotizaciones'
import { calcularTotales, redondeo2 } from '../lib/totalesDocumento'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import AppLayout from '../layouts/AppLayout.vue'
import ClienteCombobox, { type ClienteResultado } from '../components/ClienteCombobox.vue'
import UsoCfdiCombobox from '../components/UsoCfdiCombobox.vue'
import FormaPagoSelect from '../components/FormaPagoSelect.vue'
import MetodoPagoSelect from '../components/MetodoPagoSelect.vue'
import DocumentoLineas, { type LineaEditable } from '../components/DocumentoLineas.vue'
import { aplicarPrecioCliente } from '../lib/precioClienteLinea'

const route = useRoute()
const router = useRouter()
const facturas = useFacturasStore()
const cotizacionesStore = useCotizacionesStore()

const facturaId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => facturaId.value !== null)

// Al facturar desde una cotización (ver 008-cotizaciones.md, "Conversión a factura"), el
// formulario se precarga con el cliente (fijo, no editable) y las líneas de la cotización.
const cotizacionId = computed(() => {
  const id = route.query.cotizacion_id
  return typeof id === 'string' ? Number(id) : null
})
const clienteFijoNombre = ref<string | null>(null)
/**
 * Descuento de cliente que traía la cotización de origen. Solo se usa para explicar en pantalla por
 * qué los precios no coinciden con el catálogo: a la factura no viaja como descuento, va plegado
 * dentro del precio unitario (ver 015-descuento-permanente-cliente.md).
 */
const descuentoCotizacionOrigen = ref(0)
/**
 * El cliente elegido es distribuidor (ver 033-precio-distribuidor.md): precarga cada línea nueva
 * con el precio distribuidor. A diferencia del descuento permanente (015), sí aplica en una
 * factura creada desde cero.
 */
const esClienteDistribuidorActual = ref(false)

/**
 * Facturas por monto parcial (ver 043-facturas-parciales-cotizacion.md): la cotización de origen,
 * completa, para leer su saldo pendiente por facturar y saber si ya tiene alguna factura.
 */
const cotizacionOrigen = ref<Cotizacion | null>(null)
/** Las líneas de la cotización, precargadas aparte para poder restaurarlas al volver a "el total". */
const lineasCotizacionOrigen = ref<LineaEditable[]>([])
/**
 * "El total" solo se ofrece cuando la cotización todavía no tiene ninguna factura: en cuanto ya
 * tiene una, ya no cabe en el saldo pendiente por facturar.
 */
const cotizacionSinFacturas = computed(() => (cotizacionOrigen.value?.facturas.length ?? 0) === 0)
const modoFactura = ref<'total' | 'parcial'>('total')

/**
 * "Un monto parcial" no es una tabla de artículos: el usuario escribe cuánto quiere facturar y
 * con qué descripción, y el sistema arma una sola línea libre (sin artículo del catálogo) con la
 * tasa general de IVA (16%), la misma que usa cualquier línea nueva del formulario. Ver
 * "Por qué las dos reglas del usuario son la misma regla" en la spec: el backend topa el monto al
 * saldo pendiente por facturar sin importar de dónde vino la línea.
 */
const montoParcial = ref<number | null>(null)
const descripcionParcial = ref('')
/**
 * El descuento global de la cotización (si tenía uno) solo aplica en modo "total" —es el mismo
 * documento, con las mismas líneas—. En "parcial" el monto que escribe el usuario ya es el total
 * exacto a facturar, así que el descuento se apaga mientras dure ese modo y se recupera aquí al
 * volver a "el total".
 */
const descuentoGlobalTipoOrigen = ref<TipoDescuento | null>(null)
const descuentoGlobalValorOrigen = ref<number | null>(null)

/** Precio unitario (sin IVA) que, a la tasa general, cierra en el monto capturado. */
function precioSinIvaDesdeMonto(monto: number): number {
  return redondeo2(monto / 1.16)
}

/** Aviso inmediato en pantalla; el backend es quien de verdad topa el monto (043). */
const montoExcedeSaldo = computed(() => {
  if (modoFactura.value !== 'parcial' || cotizacionOrigen.value === null) return false

  return (montoParcial.value ?? 0) > cotizacionOrigen.value.saldo_pendiente_facturar
})

function construirLineaParcial(): LineaEditable[] {
  const monto = montoParcial.value

  if (monto === null || monto <= 0 || descripcionParcial.value.trim() === '') {
    return []
  }

  return [
    {
      articulo_id: null,
      cantidad: 1,
      descripcion: descripcionParcial.value.trim(),
      modelo: descripcionParcial.value.trim(),
      precio_unitario: precioSinIvaDesdeMonto(monto),
      descuento_tipo: null,
      descuento_valor: null,
      tasa_iva: '16',
    },
  ]
}

const form = reactive({
  cliente_id: null as number | null,
  uso_cfdi: null as string | null,
  forma_pago: null as string | null,
  metodo_pago: null as string | null,
  descuento_global_tipo: null as TipoDescuento | null,
  descuento_global_valor: null as number | null,
})

const lineas = ref<LineaEditable[]>([])

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

// Mismo módulo de cálculo que usa el componente de líneas para su desglose y que replica al
// backend, atado a él por el fixture compartido (ver 012-ordenes-compra.md, adición técnica 42).
const totales = computed(() =>
  calcularTotales(lineas.value, form.descuento_global_tipo, form.descuento_global_valor, true),
)

onMounted(async () => {
  if (facturaId.value) {
    cargando.value = true
    try {
      const factura = await facturas.fetchOne(facturaId.value)
      form.cliente_id = factura.cliente_id
      esClienteDistribuidorActual.value = factura.cliente_es_distribuidor
      form.uso_cfdi = factura.uso_cfdi
      form.forma_pago = factura.forma_pago
      form.metodo_pago = factura.metodo_pago
      form.descuento_global_tipo = factura.descuento_global_tipo
      form.descuento_global_valor = factura.descuento_global_valor
      lineas.value = factura.lineas.map((l) => ({
        articulo_id: l.articulo_id,
        cantidad: l.cantidad,
        descripcion: l.descripcion,
        modelo: l.modelo,
        precio_unitario: l.precio_unitario,
        descuento_tipo: l.descuento_tipo,
        descuento_valor: l.descuento_valor,
        tasa_iva: l.tasa_iva,
      }))
      if (factura.error_timbrado) {
        errorGeneral.value = factura.error_timbrado
      }
    } catch (err) {
      errorGeneral.value = extractErrorMessage(err)
    } finally {
      cargando.value = false
    }
    return
  }

  if (cotizacionId.value) {
    cargando.value = true
    try {
      const cotizacion = await cotizacionesStore.fetchOne(cotizacionId.value)
      cotizacionOrigen.value = cotizacion
      form.cliente_id = cotizacion.cliente_id
      clienteFijoNombre.value = cotizacion.cliente_razon_social
      descuentoCotizacionOrigen.value = cotizacion.descuento_cliente_porcentaje
      // El cliente es fijo en esta pantalla, pero el combobox de artículos sigue activo: si se
      // agrega una línea nueva antes de guardar, debe nacer con el precio correcto (ver 033).
      esClienteDistribuidorActual.value = cotizacion.cliente_es_distribuidor
      // El descuento global sí viaja tal cual: el usuario lo capturó explícitamente para este
      // documento y no hace falta plegarlo para que los totales cuadren (ver 015). Solo aplica en
      // modo "total" — ver el comentario de descuentoGlobalTipoOrigen más arriba.
      descuentoGlobalTipoOrigen.value = cotizacion.descuento_global_tipo
      descuentoGlobalValorOrigen.value = cotizacion.descuento_global_valor
      form.descuento_global_tipo = cotizacion.descuento_global_tipo
      form.descuento_global_valor = cotizacion.descuento_global_valor
      lineasCotizacionOrigen.value = cotizacion.lineas.map((l) => ({
        articulo_id: l.articulo_id,
        cantidad: l.cantidad,
        descripcion: l.descripcion,
        modelo: l.modelo,
        // El descuento de línea se pliega dentro del precio y desaparece del documento fiscal: el
        // precio ya rebajado lo calcula el backend, aquí solo se consume (ver 015).
        precio_unitario: l.precio_unitario_facturacion,
        descuento_tipo: null,
        descuento_valor: null,
        tasa_iva: l.tasa_iva,
      }))

      descripcionParcial.value = `Anticipo cotización ${cotizacion.folio}`

      // Facturas por monto parcial (ver 043): si la cotización ya tiene alguna factura, "el
      // total" ya no cabe en el saldo pendiente por facturar — arranca directo en modo "monto
      // parcial", con el saldo pendiente sugerido como punto de partida.
      if (cotizacionSinFacturas.value) {
        modoFactura.value = 'total'
        lineas.value = lineasCotizacionOrigen.value
      } else {
        modoFactura.value = 'parcial'
        montoParcial.value = cotizacion.saldo_pendiente_facturar
        form.descuento_global_tipo = null
        form.descuento_global_valor = null
      }
    } catch (err) {
      errorGeneral.value = extractErrorMessage(err)
    } finally {
      cargando.value = false
    }
  }
})

/** Cambia entre precargar las líneas completas de la cotización o pasar a monto parcial. */
function onCambiarModoFactura(nuevo: 'total' | 'parcial') {
  modoFactura.value = nuevo
  if (nuevo === 'total') {
    lineas.value = lineasCotizacionOrigen.value
    form.descuento_global_tipo = descuentoGlobalTipoOrigen.value
    form.descuento_global_valor = descuentoGlobalValorOrigen.value
    return
  }

  form.descuento_global_tipo = null
  form.descuento_global_valor = null
  if (montoParcial.value === null) {
    montoParcial.value = cotizacionOrigen.value?.saldo_pendiente_facturar ?? null
  }
  lineas.value = construirLineaParcial()
}

// El monto y la descripción capturados arman, en vivo, la única línea que se envía en modo
// "parcial" — sin esto, cambiar el monto no se reflejaría en `totales` ni en el payload.
watch([montoParcial, descripcionParcial], () => {
  if (modoFactura.value === 'parcial') {
    lineas.value = construirLineaParcial()
  }
})

/**
 * Solo se dispara cuando el combobox está visible, es decir, en una factura creada desde cero o en
 * edición (nunca cuando viene de una cotización, donde el cliente es fijo). Reemplaza el precio de
 * las líneas ya capturadas por el que corresponda al cliente nuevo (ver 033-precio-distribuidor.md).
 * A diferencia del descuento permanente (015), aquí sí aplica: no hay un documento intermedio que
 * explique de dónde salió el precio.
 */
async function onClienteSeleccionado(cliente: ClienteResultado | null) {
  esClienteDistribuidorActual.value = cliente?.es_distribuidor ?? false
  lineas.value = await aplicarPrecioCliente(lineas.value, esClienteDistribuidorActual.value)
}

async function onSubmit() {
  guardando.value = true
  errorGeneral.value = null
  erroresPorCampo.value = {}

  const payload: FacturaPayload = {
    cliente_id: form.cliente_id,
    uso_cfdi: form.uso_cfdi,
    forma_pago: form.forma_pago,
    metodo_pago: form.metodo_pago as FacturaPayload['metodo_pago'],
    descuento_global_tipo: form.descuento_global_tipo,
    descuento_global_valor: form.descuento_global_valor,
    lineas: lineas.value,
    total: totales.value.total,
    cotizacion_id: !esEdicion.value ? cotizacionId.value : undefined,
  }

  try {
    const factura =
      esEdicion.value && facturaId.value
        ? await facturas.update(facturaId.value, payload)
        : await facturas.create(payload)

    if (factura.estado === 'timbrada') {
      await router.push({ name: 'facturas-detalle', params: { id: factura.id } })
    } else {
      errorGeneral.value = factura.error_timbrado ?? 'No se pudo timbrar la factura.'
      if (!esEdicion.value) {
        await router.push({ name: 'facturas-editar', params: { id: factura.id } })
      }
    }
  } catch (err) {
    erroresPorCampo.value = extractFieldErrors(err)
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-4xl space-y-4">
      <h1 class="font-heading text-foreground text-xl font-semibold">
        {{ esEdicion ? 'Reintentar factura' : 'Nueva factura' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <!-- Cuando la factura viene de una cotización, manda la cotización para efectos de
           inventario. Sin este aviso la regla es invisible y la existencia intacta después de
           timbrar parece un error (ver 017-inventario.md). -->
      <Alert v-if="cotizacionId && !esEdicion">
        <AlertDescription>
          El inventario se descontará al marcar la cotización como
          <strong>producto entregado</strong>, no al timbrar esta factura: la mercancía sale cuando
          sale de tu bodega.
        </AlertDescription>
      </Alert>

      <!-- Facturas por monto parcial (ver 043-facturas-parciales-cotizacion.md): solo se ofrece
           elegir cuando la cotización todavía no tiene ninguna factura. En cuanto ya tiene una,
           "el total" ya no cabe en el saldo pendiente por facturar. -->
      <div v-if="cotizacionId && !esEdicion && cotizacionSinFacturas" class="space-y-1.5">
        <Label>¿Qué vas a facturar?</Label>
        <div class="flex gap-2">
          <Button
            type="button"
            size="sm"
            :variant="modoFactura === 'total' ? 'default' : 'outline'"
            @click="onCambiarModoFactura('total')"
          >
            El total de la cotización
          </Button>
          <Button
            type="button"
            size="sm"
            :variant="modoFactura === 'parcial' ? 'default' : 'outline'"
            @click="onCambiarModoFactura('parcial')"
          >
            Un monto parcial
          </Button>
        </div>
      </div>

      <!-- Monto parcial (ver 043): no es una tabla de artículos, es cuánto se factura y con qué
           descripción. El sistema arma una sola línea libre a la tasa general de IVA. -->
      <Card v-if="cotizacionId && !esEdicion && modoFactura === 'parcial'">
        <CardHeader>
          <CardTitle class="text-base">Monto a facturar</CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
          <p class="text-muted-foreground text-sm">
            Saldo pendiente por facturar: ${{
              cotizacionOrigen?.saldo_pendiente_facturar.toFixed(2)
            }}
            de ${{ cotizacionOrigen?.total.toFixed(2) }}.
          </p>
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
              <Label>Monto (IVA incluido)</Label>
              <Input
                :model-value="montoParcial ?? undefined"
                type="number"
                step="0.01"
                min="0.01"
                @update:model-value="(v) => (montoParcial = v === '' ? null : Number(v))"
              />
              <p v-if="montoExcedeSaldo" class="text-destructive text-sm">
                Ese monto excede el saldo pendiente por facturar.
              </p>
            </div>
            <div class="space-y-1.5">
              <Label>Descripción</Label>
              <Input v-model="descripcionParcial" type="text" maxlength="255" />
            </div>
          </div>
          <p v-if="totales.total > 0" class="text-muted-foreground text-sm">
            Total a facturar: ${{ totales.total.toFixed(2) }}
          </p>
          <p
            v-if="erroresPorCampo.total || erroresPorCampo.lineas"
            class="text-destructive text-sm"
          >
            {{ erroresPorCampo.total || erroresPorCampo.lineas }}
          </p>
        </CardContent>
      </Card>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Cliente y datos fiscales</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label>Cliente</Label>
              <ClienteCombobox
                v-if="!clienteFijoNombre"
                v-model="form.cliente_id"
                @seleccion="onClienteSeleccionado"
              />
              <p
                v-else
                class="border-input bg-muted text-muted-foreground rounded-md border px-3 py-2 text-sm"
              >
                {{ clienteFijoNombre }} (fijo, viene de la cotización)
              </p>
              <p v-if="erroresPorCampo.cliente_id" class="text-destructive text-sm">
                {{ erroresPorCampo.cliente_id }}
              </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
              <div class="space-y-1.5">
                <Label>Uso de CFDI</Label>
                <UsoCfdiCombobox v-model="form.uso_cfdi" />
                <p v-if="erroresPorCampo.uso_cfdi" class="text-destructive text-sm">
                  {{ erroresPorCampo.uso_cfdi }}
                </p>
              </div>
              <div class="space-y-1.5">
                <Label>Forma de pago</Label>
                <FormaPagoSelect v-model="form.forma_pago" />
                <p v-if="erroresPorCampo.forma_pago" class="text-destructive text-sm">
                  {{ erroresPorCampo.forma_pago }}
                </p>
              </div>
              <div class="space-y-1.5">
                <Label>Método de pago</Label>
                <MetodoPagoSelect v-model="form.metodo_pago" />
                <p v-if="erroresPorCampo.metodo_pago" class="text-destructive text-sm">
                  {{ erroresPorCampo.metodo_pago }}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>

        <template v-if="modoFactura === 'total'">
          <Alert v-if="descuentoCotizacionOrigen > 0">
            <AlertDescription>
              Los precios unitarios ya incluyen el descuento de
              <strong>{{ descuentoCotizacionOrigen }}%</strong> de este cliente. La factura no
              mostrará el descuento por separado.
            </AlertDescription>
          </Alert>

          <Alert v-if="esClienteDistribuidorActual">
            <AlertDescription>
              Este cliente es distribuidor: cada línea usa el precio distribuidor.
            </AlertDescription>
          </Alert>

          <DocumentoLineas
            v-model:lineas="lineas"
            v-model:descuento-global-tipo="form.descuento_global_tipo"
            v-model:descuento-global-valor="form.descuento_global_valor"
            :error-lineas="erroresPorCampo.lineas"
            :precio-distribuidor="esClienteDistribuidorActual"
            redondear-al-peso
          />
        </template>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'facturas' })">
            Cancelar
          </Button>
          <Button type="submit" :disabled="guardando || lineas.length === 0 || montoExcedeSaldo">
            {{ guardando ? 'Generando...' : 'Generar y timbrar' }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
