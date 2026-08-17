<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowDownTrayIcon,
  EnvelopeIcon,
  NoSymbolIcon,
  BanknotesIcon,
} from '@heroicons/vue/24/outline'
import { useFacturasStore, type Factura, type EstadoFactura } from '../stores/facturas'
import { extractErrorMessage } from '../lib/errors'
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
import MotivoCancelacionSelect from '../components/MotivoCancelacionSelect.vue'
import FacturaSustitutaCombobox from '../components/FacturaSustitutaCombobox.vue'
import FormaPagoSelect from '../components/FormaPagoSelect.vue'
import AvisoEmisorIncompleto from '../components/AvisoEmisorIncompleto.vue'

const route = useRoute()
const router = useRouter()
const facturasStore = useFacturasStore()

const factura = ref<Factura | null>(null)
const cargando = ref(true)
const errorGeneral = ref<string | null>(null)

const facturaId = computed(() => Number(route.params.id))

function estadoVariant(estado: EstadoFactura) {
  return {
    timbrada: 'success',
    pendiente: 'warning',
    cancelada: 'destructive',
    borrador: 'secondary',
  }[estado] as 'success' | 'warning' | 'destructive' | 'secondary'
}

async function cargar() {
  cargando.value = true
  try {
    factura.value = await facturasStore.fetchOne(facturaId.value)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

// Enviar por correo.
const mostrarEnviar = ref(false)
const destinatarios = ref('')
const enviando = ref(false)
const errorEnviar = ref<string | null>(null)
const enviadoOk = ref(false)

function abrirEnviar() {
  destinatarios.value = factura.value?.cliente_correo ?? ''
  errorEnviar.value = null
  enviadoOk.value = false
  mostrarEnviar.value = true
}

async function confirmarEnviar() {
  if (!factura.value) return

  enviando.value = true
  errorEnviar.value = null
  try {
    const lista = destinatarios.value
      .split(',')
      .map((d) => d.trim())
      .filter((d) => d.length > 0)
    await facturasStore.enviarCorreo(factura.value.id, lista)
    enviadoOk.value = true
  } catch (err) {
    errorEnviar.value = extractErrorMessage(err)
  } finally {
    enviando.value = false
  }
}

// Descargas.
const descargandoXml = ref(false)
const descargandoPdf = ref(false)
const errorDescarga = ref<string | null>(null)

async function onDescargarXml() {
  if (!factura.value) return
  descargandoXml.value = true
  errorDescarga.value = null
  try {
    await facturasStore.descargarXml(factura.value)
  } catch (err) {
    errorDescarga.value = extractErrorMessage(err)
  } finally {
    descargandoXml.value = false
  }
}

async function onDescargarPdf() {
  if (!factura.value) return
  descargandoPdf.value = true
  errorDescarga.value = null
  try {
    await facturasStore.descargarPdf(factura.value)
  } catch (err) {
    errorDescarga.value = extractErrorMessage(err)
  } finally {
    descargandoPdf.value = false
  }
}

// Cancelación.
const mostrarCancelar = ref(false)
const motivoCancelacion = ref<string | null>(null)
const facturaSustitutaId = ref<number | null>(null)
const cancelando = ref(false)
const errorCancelar = ref<string | null>(null)

function abrirCancelar() {
  motivoCancelacion.value = null
  facturaSustitutaId.value = null
  errorCancelar.value = null
  mostrarCancelar.value = true
}

async function confirmarCancelar() {
  if (!factura.value || !motivoCancelacion.value) return

  cancelando.value = true
  errorCancelar.value = null
  try {
    factura.value = await facturasStore.cancelar(factura.value.id, {
      motivo_cancelacion: motivoCancelacion.value,
      factura_sustituta_id: facturaSustitutaId.value,
    })
    mostrarCancelar.value = false
  } catch (err) {
    errorCancelar.value = extractErrorMessage(err)
  } finally {
    cancelando.value = false
  }
}

// Complemento de pago.
const mostrarComplemento = ref(false)
const complementoFecha = ref('')
const complementoMonto = ref<number | null>(null)
const complementoFormaPago = ref<string | null>(null)
const registrandoComplemento = ref(false)
const errorComplemento = ref<string | null>(null)

function abrirComplemento() {
  complementoFecha.value = new Date().toISOString().slice(0, 10)
  complementoMonto.value = factura.value?.total ?? null
  complementoFormaPago.value = null
  errorComplemento.value = null
  mostrarComplemento.value = true
}

async function confirmarComplemento() {
  if (!factura.value || !complementoMonto.value || !complementoFormaPago.value) return

  registrandoComplemento.value = true
  errorComplemento.value = null
  try {
    await facturasStore.registrarComplementoPago(factura.value.id, {
      fecha_pago: complementoFecha.value,
      monto: complementoMonto.value,
      forma_pago: complementoFormaPago.value,
    })
    mostrarComplemento.value = false
    await cargar()
  } catch (err) {
    errorComplemento.value = extractErrorMessage(err)
  } finally {
    registrandoComplemento.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-4xl space-y-4">
      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <template v-if="!cargando && factura">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 class="font-heading text-foreground text-xl font-semibold">
              Factura {{ factura.facturapi_serie }}{{ factura.facturapi_folio ?? factura.folio }}
            </h1>
            <p class="text-muted-foreground text-sm">{{ factura.cliente_razon_social }}</p>
          </div>
          <Badge :variant="estadoVariant(factura.estado)">
            {{ factura.estado }}
          </Badge>
        </div>

        <Alert
          v-if="factura.estado === 'pendiente' && factura.error_timbrado"
          variant="destructive"
        >
          <AlertDescription>{{ factura.error_timbrado }}</AlertDescription>
        </Alert>

        <Alert v-if="factura.estado_cancelacion && factura.estado_cancelacion !== 'accepted'">
          <AlertDescription>
            Cancelación en proceso ({{ factura.estado_cancelacion }}). Vuelve a abrir esta pantalla
            para verificar si ya se confirmó.
          </AlertDescription>
        </Alert>

        <AvisoEmisorIncompleto />

        <div v-if="factura.estado === 'timbrada'" class="flex flex-wrap gap-2">
          <Button variant="outline" @click="abrirEnviar">
            <EnvelopeIcon class="size-4" />
            Enviar
          </Button>
          <Button variant="outline" :disabled="descargandoXml" @click="onDescargarXml">
            <ArrowDownTrayIcon class="size-4" />
            {{ descargandoXml ? 'Descargando...' : 'Descargar XML' }}
          </Button>
          <Button variant="outline" :disabled="descargandoPdf" @click="onDescargarPdf">
            <ArrowDownTrayIcon class="size-4" />
            {{ descargandoPdf ? 'Descargando...' : 'Descargar PDF' }}
          </Button>
          <Button variant="outline" @click="abrirCancelar">
            <NoSymbolIcon class="size-4" />
            Cancelar
          </Button>
          <Button
            v-if="factura.metodo_pago === 'PPD' && !factura.complemento_pago"
            variant="outline"
            @click="abrirComplemento"
          >
            <BanknotesIcon class="size-4" />
            Registrar complemento de pago
          </Button>
        </div>

        <Alert v-if="errorDescarga" variant="destructive">
          <AlertDescription>{{ errorDescarga }}</AlertDescription>
        </Alert>

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos del comprobante</CardTitle>
          </CardHeader>
          <CardContent class="grid gap-2 text-sm sm:grid-cols-2">
            <p><strong>UUID fiscal:</strong> {{ factura.uuid_fiscal ?? '—' }}</p>
            <p><strong>Fecha de timbrado:</strong> {{ factura.fecha_timbrado ?? '—' }}</p>
            <p><strong>Uso de CFDI:</strong> {{ factura.uso_cfdi }}</p>
            <p><strong>Forma de pago:</strong> {{ factura.forma_pago }}</p>
            <p><strong>Método de pago:</strong> {{ factura.metodo_pago }}</p>
            <p><strong>Moneda:</strong> {{ factura.moneda }}</p>
            <p class="break-all sm:col-span-2">
              <strong>Cadena original del SAT:</strong> {{ factura.cadena_original_sat ?? '—' }}
            </p>
          </CardContent>
        </Card>

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
                <TableRow v-for="linea in factura.lineas" :key="linea.id">
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
              <span>Subtotal</span><span>${{ factura.subtotal.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Descuento</span><span>${{ factura.total_descuento.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>IVA 16%</span><span>${{ factura.total_iva_16.toFixed(2) }}</span>
            </div>
            <div v-if="factura.ajuste_al_peso > 0" class="flex justify-between">
              <span>Ajuste al peso</span><span>${{ factura.ajuste_al_peso.toFixed(2) }}</span>
            </div>
            <div class="text-foreground flex justify-between border-t pt-1 text-base font-semibold">
              <span>Total</span><span>${{ factura.total.toFixed(2) }}</span>
            </div>
          </CardContent>
        </Card>

        <div>
          <Button variant="outline" @click="router.push({ name: 'facturas' })"
            >Volver al listado</Button
          >
        </div>
      </template>

      <Dialog :open="mostrarEnviar" @update:open="(v) => (mostrarEnviar = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Enviar factura por correo</DialogTitle>
            <DialogDescription>
              Se enviará el XML y el PDF adjuntos a los destinatarios indicados (separados por
              coma).
            </DialogDescription>
          </DialogHeader>
          <div class="min-w-0 space-y-1.5">
            <Label for="destinatarios">Destinatarios</Label>
            <Input
              id="destinatarios"
              v-model="destinatarios"
              placeholder="correo1@ejemplo.com, correo2@ejemplo.com"
            />
          </div>
          <Alert v-if="errorEnviar" variant="destructive">
            <AlertDescription>{{ errorEnviar }}</AlertDescription>
          </Alert>
          <Alert v-if="enviadoOk" variant="success">
            <AlertDescription>Correo enviado correctamente.</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="enviando" @click="mostrarEnviar = false"
              >Cerrar</Button
            >
            <Button :disabled="enviando || !destinatarios" @click="confirmarEnviar">
              {{ enviando ? 'Enviando...' : 'Enviar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarCancelar" @update:open="(v) => (mostrarCancelar = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancelar factura</DialogTitle>
            <DialogDescription>Selecciona el motivo de cancelación ante el SAT.</DialogDescription>
          </DialogHeader>
          <div class="min-w-0 space-y-4">
            <div class="space-y-1.5">
              <Label>Motivo</Label>
              <MotivoCancelacionSelect v-model="motivoCancelacion" />
            </div>
            <div v-if="motivoCancelacion === '01'" class="space-y-1.5">
              <Label>Factura sustituta</Label>
              <FacturaSustitutaCombobox v-model="facturaSustitutaId" />
            </div>
          </div>
          <Alert v-if="errorCancelar" variant="destructive">
            <AlertDescription>{{ errorCancelar }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="cancelando" @click="mostrarCancelar = false">
              Cerrar
            </Button>
            <Button
              variant="destructive"
              :disabled="
                cancelando ||
                !motivoCancelacion ||
                (motivoCancelacion === '01' && !facturaSustitutaId)
              "
              @click="confirmarCancelar"
            >
              {{ cancelando ? 'Cancelando...' : 'Cancelar factura' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarComplemento" @update:open="(v) => (mostrarComplemento = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Registrar complemento de pago</DialogTitle>
            <DialogDescription
              >Se timbrará un CFDI de pago asociado a esta factura.</DialogDescription
            >
          </DialogHeader>
          <div class="min-w-0 space-y-4">
            <div class="space-y-1.5">
              <Label for="fecha_pago">Fecha de pago</Label>
              <Input id="fecha_pago" v-model="complementoFecha" type="date" />
            </div>
            <div class="space-y-1.5">
              <Label for="monto">Monto</Label>
              <Input
                id="monto"
                :model-value="complementoMonto ?? undefined"
                type="number"
                min="0.01"
                step="0.01"
                @update:model-value="(v) => (complementoMonto = v === '' ? null : Number(v))"
              />
            </div>
            <div class="space-y-1.5">
              <Label>Forma de pago</Label>
              <FormaPagoSelect v-model="complementoFormaPago" />
            </div>
          </div>
          <Alert v-if="errorComplemento" variant="destructive">
            <AlertDescription>{{ errorComplemento }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button
              variant="outline"
              :disabled="registrandoComplemento"
              @click="mostrarComplemento = false"
            >
              Cerrar
            </Button>
            <Button :disabled="registrandoComplemento" @click="confirmarComplemento">
              {{ registrandoComplemento ? 'Registrando...' : 'Registrar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
