<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useFacturasStore, type FacturaPayload, type TipoDescuento } from '../stores/facturas'
import { useCotizacionesStore } from '../stores/cotizaciones'
import { calcularTotales } from '../lib/totalesDocumento'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import AppLayout from '../layouts/AppLayout.vue'
import ClienteCombobox from '../components/ClienteCombobox.vue'
import UsoCfdiCombobox from '../components/UsoCfdiCombobox.vue'
import FormaPagoSelect from '../components/FormaPagoSelect.vue'
import MetodoPagoSelect from '../components/MetodoPagoSelect.vue'
import DocumentoLineas, { type LineaEditable } from '../components/DocumentoLineas.vue'

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
      form.cliente_id = cotizacion.cliente_id
      clienteFijoNombre.value = cotizacion.cliente_razon_social
      descuentoCotizacionOrigen.value = cotizacion.descuento_cliente_porcentaje
      // El descuento global sí viaja tal cual: el usuario lo capturó explícitamente para este
      // documento y no hace falta plegarlo para que los totales cuadren (ver 015).
      form.descuento_global_tipo = cotizacion.descuento_global_tipo
      form.descuento_global_valor = cotizacion.descuento_global_valor
      lineas.value = cotizacion.lineas.map((l) => ({
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
    } catch (err) {
      errorGeneral.value = extractErrorMessage(err)
    } finally {
      cargando.value = false
    }
  }
})

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

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Cliente y datos fiscales</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label>Cliente</Label>
              <ClienteCombobox v-if="!clienteFijoNombre" v-model="form.cliente_id" />
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

        <Alert v-if="descuentoCotizacionOrigen > 0">
          <AlertDescription>
            Los precios unitarios ya incluyen el descuento de
            <strong>{{ descuentoCotizacionOrigen }}%</strong> de este cliente. La factura no
            mostrará el descuento por separado.
          </AlertDescription>
        </Alert>

        <DocumentoLineas
          v-model:lineas="lineas"
          v-model:descuento-global-tipo="form.descuento_global_tipo"
          v-model:descuento-global-valor="form.descuento_global_valor"
          :error-lineas="erroresPorCampo.lineas"
          redondear-al-peso
        />

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'facturas' })">
            Cancelar
          </Button>
          <Button type="submit" :disabled="guardando || lineas.length === 0">
            {{ guardando ? 'Generando...' : 'Generar y timbrar' }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
