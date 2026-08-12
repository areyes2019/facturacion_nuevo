<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowDownTrayIcon,
  ArrowUturnLeftIcon,
  BanknotesIcon,
  DocumentDuplicateIcon,
  EnvelopeIcon,
  InboxArrowDownIcon,
} from '@heroicons/vue/24/outline'
import {
  useOrdenesCompraStore,
  type EstadoOrdenCompra,
  type OrdenCompra,
} from '../stores/ordenesCompra'
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
import CuentaSelect from '../components/CuentaSelect.vue'
import AvisoEmisorIncompleto from '../components/AvisoEmisorIncompleto.vue'

const route = useRoute()
const router = useRouter()
const ordenesStore = useOrdenesCompraStore()

const orden = ref<OrdenCompra | null>(null)
const cargando = ref(true)
const errorGeneral = ref<string | null>(null)

const ordenId = computed(() => Number(route.params.id))

function estadoVariant(estado: EstadoOrdenCompra) {
  return {
    borrador: 'secondary',
    enviada: 'warning',
    pagada: 'success',
    recibida: 'default',
  }[estado] as 'secondary' | 'warning' | 'success' | 'default'
}

async function cargar() {
  cargando.value = true
  try {
    orden.value = await ordenesStore.fetchOne(ordenId.value)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

// Enviar por correo/WhatsApp.
const mostrarEnviar = ref(false)
const canalEnvio = ref<'correo' | 'whatsapp'>('correo')
const destinatarios = ref('')
const telefono = ref('')
const enviando = ref(false)
const errorEnviar = ref<string | null>(null)
const enviadoOk = ref(false)

function abrirEnviar() {
  canalEnvio.value = 'correo'
  destinatarios.value = orden.value?.proveedor_correo ?? ''
  telefono.value = orden.value?.proveedor_telefono ?? ''
  errorEnviar.value = null
  enviadoOk.value = false
  mostrarEnviar.value = true
}

async function confirmarEnviar() {
  if (!orden.value) return

  enviando.value = true
  errorEnviar.value = null
  try {
    if (canalEnvio.value === 'correo') {
      const lista = destinatarios.value
        .split(',')
        .map((d) => d.trim())
        .filter((d) => d.length > 0)
      await ordenesStore.enviar(orden.value.id, { canal: 'correo', destinatarios: lista })
    } else {
      await ordenesStore.enviar(orden.value.id, { canal: 'whatsapp', telefono: telefono.value })
    }
    enviadoOk.value = true
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
  if (!orden.value) return
  descargandoPdf.value = true
  errorDescarga.value = null
  try {
    await ordenesStore.descargarPdf(orden.value)
  } catch (err) {
    errorDescarga.value = extractErrorMessage(err)
  } finally {
    descargandoPdf.value = false
  }
}

// Pago de contado: único y por el total de la orden. El monto no es editable — lo toma el servidor
// de la propia orden (ver 012-ordenes-compra.md).
const mostrarPago = ref(false)
const fechaPago = ref('')
const cuentaPago = ref<number | null>(null)
const registrandoPago = ref(false)
const errorPago = ref<string | null>(null)

function abrirPago() {
  fechaPago.value = new Date().toISOString().slice(0, 10)
  cuentaPago.value = null
  errorPago.value = null
  mostrarPago.value = true
}

async function confirmarPago() {
  if (!orden.value || !cuentaPago.value) return

  registrandoPago.value = true
  errorPago.value = null
  try {
    orden.value = await ordenesStore.pagar(orden.value.id, {
      cuenta_id: cuentaPago.value,
      fecha_pago: fechaPago.value,
    })
    mostrarPago.value = false
  } catch (err) {
    // Incluye el rechazo por saldo insuficiente: el modal se queda abierto con el mensaje.
    errorPago.value = extractErrorMessage(err)
  } finally {
    registrandoPago.value = false
  }
}

// Cancelar el pago: revierte el egreso en Tesorería y deja la orden editable otra vez.
const mostrarCancelarPago = ref(false)
const cancelandoPago = ref(false)
const errorCancelarPago = ref<string | null>(null)

async function confirmarCancelarPago() {
  if (!orden.value) return

  cancelandoPago.value = true
  errorCancelarPago.value = null
  try {
    orden.value = await ordenesStore.cancelarPago(orden.value.id)
    mostrarCancelarPago.value = false
  } catch (err) {
    errorCancelarPago.value = extractErrorMessage(err)
  } finally {
    cancelandoPago.value = false
  }
}

// Recibir la mercancía. Desde 017-inventario.md esto SUMA las cantidades de las líneas a las
// existencias, así que se confirma antes: la recepción es total y no se puede deshacer.
const recibiendo = ref(false)
const mostrarRecibir = ref(false)
async function onRecibir() {
  if (!orden.value) return
  recibiendo.value = true
  try {
    orden.value = await ordenesStore.recibir(orden.value.id)
    mostrarRecibir.value = false
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    recibiendo.value = false
  }
}

// Duplicar.
const duplicando = ref(false)
async function onDuplicar() {
  if (!orden.value) return
  duplicando.value = true
  try {
    const copia = await ordenesStore.duplicar(orden.value.id)
    await router.push({ name: 'ordenes-compra-detalle', params: { id: copia.id } })
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    duplicando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-4xl space-y-4">
      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <template v-if="!cargando && orden">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 class="font-heading text-foreground text-xl font-semibold">
              Orden de compra {{ orden.folio_formateado }}
            </h1>
            <p class="text-muted-foreground text-sm">{{ orden.proveedor_nombre_comercial }}</p>
          </div>
          <Badge :variant="estadoVariant(orden.estado)">{{ orden.estado }}</Badge>
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
          <Button v-if="orden.estado === 'enviada'" variant="outline" @click="abrirPago">
            <BanknotesIcon class="size-4" />
            Registrar pago
          </Button>
          <template v-if="orden.estado === 'pagada'">
            <Button variant="outline" @click="mostrarCancelarPago = true">
              <ArrowUturnLeftIcon class="size-4" />
              Cancelar pago
            </Button>
            <Button variant="outline" :disabled="recibiendo" @click="mostrarRecibir = true">
              <InboxArrowDownIcon class="size-4" />
              Marcar como recibida
            </Button>
          </template>
          <Button variant="outline" :disabled="duplicando" @click="onDuplicar">
            <DocumentDuplicateIcon class="size-4" />
            Duplicar
          </Button>
        </div>

        <Alert v-if="errorDescarga" variant="destructive">
          <AlertDescription>{{ errorDescarga }}</AlertDescription>
        </Alert>

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos de la orden</CardTitle>
          </CardHeader>
          <CardContent class="space-y-2 text-sm">
            <div class="flex justify-between">
              <span>Proveedor</span><span>{{ orden.proveedor_nombre_comercial ?? '—' }}</span>
            </div>
            <div class="flex justify-between">
              <span>Fecha esperada de entrega</span>
              <span>{{ orden.fecha_entrega_esperada ?? '—' }}</span>
            </div>
            <div class="flex justify-between">
              <span>Pago</span>
              <span>
                {{
                  orden.esta_pagada
                    ? `${orden.fecha_pago} — ${orden.cuenta_nombre ?? 'cuenta eliminada'}`
                    : 'Pendiente'
                }}
              </span>
            </div>
            <div v-if="orden.observaciones" class="pt-2">
              <p class="text-muted-foreground">Observaciones</p>
              <p class="whitespace-pre-line">{{ orden.observaciones }}</p>
            </div>
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
                  <TableHead class="text-right">Costo unitario</TableHead>
                  <TableHead>IVA</TableHead>
                  <TableHead class="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="linea in orden.lineas" :key="linea.id">
                  <TableCell>{{ linea.cantidad }}</TableCell>
                  <TableCell>{{ linea.descripcion }}</TableCell>
                  <TableCell>{{ linea.modelo }}</TableCell>
                  <TableCell class="text-right">${{ linea.precio_unitario.toFixed(2) }}</TableCell>
                  <TableCell>
                    {{ linea.tasa_iva === 'exento' ? 'Exento' : linea.tasa_iva + '%' }}
                  </TableCell>
                  <TableCell class="text-right">${{ linea.importe.toFixed(2) }}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="ml-auto max-w-xs space-y-1 pt-6 text-sm">
            <div class="flex justify-between">
              <span>Subtotal</span><span>${{ orden.subtotal.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>Descuento</span><span>${{ orden.total_descuento.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span>IVA 16%</span><span>${{ orden.total_iva_16.toFixed(2) }}</span>
            </div>
            <div class="text-foreground flex justify-between border-t pt-1 text-base font-semibold">
              <span>Total</span><span>${{ orden.total.toFixed(2) }}</span>
            </div>
          </CardContent>
        </Card>

        <div>
          <Button variant="outline" @click="router.push({ name: 'ordenes-compra' })">
            Volver al listado
          </Button>
        </div>
      </template>

      <Dialog :open="mostrarEnviar" @update:open="(v) => (mostrarEnviar = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Enviar orden de compra</DialogTitle>
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
                placeholder="compras@proveedor.com, ventas@proveedor.com"
              />
            </div>
            <div v-else class="space-y-1.5">
              <Label for="telefono">Teléfono</Label>
              <Input id="telefono" v-model="telefono" placeholder="5512345678" />
            </div>
          </div>
          <Alert v-if="errorEnviar" variant="destructive">
            <AlertDescription>{{ errorEnviar }}</AlertDescription>
          </Alert>
          <Alert v-if="enviadoOk" variant="success">
            <AlertDescription>Orden de compra enviada correctamente.</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="enviando" @click="mostrarEnviar = false">
              Cerrar
            </Button>
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
              El pago a proveedores es de contado: se registra el total de la orden en un solo
              movimiento.
            </DialogDescription>
          </DialogHeader>
          <div class="min-w-0 space-y-4">
            <p class="text-sm">
              Se registrará un egreso de
              <strong>${{ (orden?.total ?? 0).toFixed(2) }}</strong>
              (total de la orden).
            </p>
            <div class="space-y-1.5">
              <Label for="fecha_pago">Fecha de pago</Label>
              <Input id="fecha_pago" v-model="fechaPago" type="date" />
            </div>
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
            <Button :disabled="registrandoPago || !cuentaPago" @click="confirmarPago">
              {{ registrandoPago ? 'Registrando...' : 'Registrar pago' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarCancelarPago" @update:open="(v) => (mostrarCancelarPago = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancelar pago</DialogTitle>
            <DialogDescription>
              Se eliminará el movimiento de egreso en Tesorería, el saldo de la cuenta se
              recalculará y la orden volverá a "enviada", con lo que podrás editarla de nuevo.
            </DialogDescription>
          </DialogHeader>
          <Alert v-if="errorCancelarPago" variant="destructive">
            <AlertDescription>{{ errorCancelarPago }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button
              variant="outline"
              :disabled="cancelandoPago"
              @click="mostrarCancelarPago = false"
            >
              Cerrar
            </Button>
            <Button variant="destructive" :disabled="cancelandoPago" @click="confirmarCancelarPago">
              Cancelar pago
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarRecibir" @update:open="(v) => (mostrarRecibir = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Marcar como recibida</DialogTitle>
            <DialogDescription>
              Las cantidades de todas las líneas entrarán al inventario, y si algún artículo traía
              faltante pendiente, esta entrada lo saldará primero. La recepción es total y
              <strong>no se puede deshacer</strong>: si llegó de menos, se corrige después con un
              ajuste en Existencias.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" :disabled="recibiendo" @click="mostrarRecibir = false">
              Cancelar
            </Button>
            <Button :disabled="recibiendo" @click="onRecibir">
              {{ recibiendo ? 'Recibiendo...' : 'Recibir mercancía' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
