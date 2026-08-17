<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  ArrowLeftIcon,
  BellAlertIcon,
  PencilIcon,
  PrinterIcon,
  ShareIcon,
  TrashIcon,
  DocumentTextIcon,
} from '@heroicons/vue/24/outline'
import { usePedidosStore, type Pedido } from '../stores/pedidos'
import { extractErrorMessage } from '../lib/errors'
import { compartirImagen, compartirTexto, copiarTexto } from '../lib/compartir'
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

const route = useRoute()
const router = useRouter()
const pedidos = usePedidosStore()

const pedido = ref<Pedido | null>(null)
const cargando = ref(true)
const errorGeneral = ref<string | null>(null)
const aviso = ref<string | null>(null)

/** Vista previa del ticket. Es una URL de objeto, así que hay que revocarla al salir. */
const ticketUrl = ref<string | null>(null)
const ticketBlob = ref<Blob | null>(null)
const compartiendo = ref(false)

const dialogoPago = ref(false)
const montoPago = ref<number | null>(null)
const cuentaPago = ref<number | null>(null)
const fechaPago = ref(new Date().toISOString().slice(0, 10))
const guardandoPago = ref(false)
const errorPago = ref<string | null>(null)

const pedidoId = computed(() => Number(route.params.id))

/**
 * El ticket comprueba un dinero que ya entró: sin ningún pago diría "Pagado $0.00" y el cliente
 * podría leerlo como que ya quedó todo hecho. Con el primer pago —anticipo o total— se comparte.
 */
const tienePagos = computed(() => (pedido.value?.pagos.length ?? 0) > 0)

const estadoTexto = computed(() =>
  pedido.value
    ? {
        pendiente: 'Pendiente de pago',
        anticipo: 'Con anticipo',
        pagado: 'Pagado',
        entregado: 'Entregado',
      }[pedido.value.estado]
    : '',
)

onMounted(cargar)

onBeforeUnmount(() => {
  if (ticketUrl.value) URL.revokeObjectURL(ticketUrl.value)
})

async function cargar() {
  cargando.value = true
  errorGeneral.value = null

  try {
    pedido.value = await pedidos.fetchOne(pedidoId.value)
    await cargarTicket()
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
}

/**
 * Trae el ticket dibujado por el servidor. Se vuelve a pedir después de cada pago porque el ticket
 * muestra el saldo: como no se guarda en ningún lado, cada petición lo pinta con los datos de ese
 * instante y es imposible que se quede mostrando el anterior.
 */
async function cargarTicket() {
  if (!pedido.value) return

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
  if (!pedido.value || !ticketBlob.value) return

  compartiendo.value = true
  aviso.value = null

  try {
    const resultado = await compartirImagen(
      ticketBlob.value,
      `ticket-${pedido.value.numero_ticket}.jpg`,
      pedido.value.mensaje_compartible ?? '',
    )

    if (resultado === 'descargado') {
      aviso.value =
        'Ticket descargado y mensaje copiado: arrastra la imagen a WhatsApp y pega el texto.'
    }
  } catch {
    aviso.value = 'No se pudo compartir el ticket.'
  } finally {
    compartiendo.value = false
  }
}

/**
 * Avisa al cliente que su trabajo está listo. El sello se elabora fuera del sistema, así que el
 * sistema no sabe cuándo lo está: lo aprieta el usuario, que sí. **No cambia ningún estado** y se
 * puede apretar las veces que haga falta, porque al cliente que no contesta se le insiste.
 */
async function avisarListo() {
  if (!pedido.value?.mensaje_listo) return

  aviso.value = null

  try {
    await compartirTexto(pedido.value.mensaje_listo)
  } catch {
    aviso.value = 'No se pudo compartir el aviso.'
  }
}

async function compartirAutofactura() {
  if (!pedido.value?.autofactura_url) return

  aviso.value = null

  const texto =
    `Hola ${pedido.value.cliente_nombre}, aquí puedes generar la factura de tu compra ` +
    `(ticket ${pedido.value.numero_ticket}): ${pedido.value.autofactura_url}`

  try {
    await compartirTexto(texto)
  } catch {
    aviso.value = 'No se pudo compartir el enlace.'
  }
}

async function copiarEnlace() {
  if (!pedido.value?.autofactura_url) return

  await copiarTexto(pedido.value.autofactura_url)
  aviso.value = 'Enlace copiado al portapapeles.'
}

function abrirPago() {
  montoPago.value = pedido.value?.saldo_pendiente ?? null
  fechaPago.value = new Date().toISOString().slice(0, 10)
  errorPago.value = null
  dialogoPago.value = true
}

async function guardarPago() {
  if (!pedido.value || montoPago.value === null || cuentaPago.value === null) return

  guardandoPago.value = true
  errorPago.value = null

  try {
    pedido.value = await pedidos.registrarPago(pedido.value.id, {
      fecha_pago: fechaPago.value,
      monto: montoPago.value,
      cuenta_id: cuentaPago.value,
    })
    dialogoPago.value = false
    await cargarTicket()
  } catch (err) {
    errorPago.value = extractErrorMessage(err)
  } finally {
    guardandoPago.value = false
  }
}

async function eliminarPago(pagoId: number) {
  if (!pedido.value) return

  try {
    await pedidos.eliminarPago(pedido.value.id, pagoId)
    await cargar()
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  }
}
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-5xl space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <Button variant="ghost" size="sm" @click="router.push({ name: 'pedidos' })">
          <ArrowLeftIcon class="size-4" />
          Pedidos
        </Button>

        <div class="flex flex-wrap gap-2">
          <Button v-if="pedido?.es_editable" as-child variant="outline" size="sm">
            <RouterLink :to="{ name: 'pedidos-editar', params: { id: pedidoId } }">
              <PencilIcon class="size-4" />
              Editar
            </RouterLink>
          </Button>
          <Button as-child variant="outline" size="sm">
            <RouterLink
              :to="{ name: 'pedidos-etiqueta', params: { id: pedidoId } }"
              target="_blank"
            >
              <PrinterIcon class="size-4" />
              Imprimir etiqueta
            </RouterLink>
          </Button>
          <!--
            El aviso de que el trabajo está listo. Como el sello se hace fuera del sistema, quien
            sabe cuándo lo está es el usuario: esto solo redacta el mensaje. No cambia ningún estado.
          -->
          <Button v-if="tienePagos" variant="outline" size="sm" @click="avisarListo">
            <BellAlertIcon class="size-4" />
            Avisar que está listo
          </Button>
          <Button
            v-if="tienePagos"
            size="sm"
            :disabled="compartiendo || !ticketBlob"
            @click="compartirTicket"
          >
            <ShareIcon class="size-4" />
            {{ compartiendo ? 'Compartiendo...' : 'Compartir ticket' }}
          </Button>
        </div>
      </div>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>
      <Alert v-if="aviso">
        <AlertDescription>{{ aviso }}</AlertDescription>
      </Alert>

      <template v-if="pedido && !cargando">
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="font-heading text-foreground text-xl font-semibold">
            Ticket {{ pedido.numero_ticket }}
          </h1>
          <Badge>{{ estadoTexto }}</Badge>
          <Badge v-if="pedido.factura_id" variant="success"> Facturado </Badge>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
          <div class="min-w-0 space-y-4">
            <Card>
              <CardHeader>
                <CardTitle class="text-base">Cliente</CardTitle>
              </CardHeader>
              <CardContent class="grid gap-2 text-sm sm:grid-cols-3">
                <div>
                  <p class="text-muted-foreground">Nombre</p>
                  <p>{{ pedido.cliente_nombre }}</p>
                </div>
                <div>
                  <p class="text-muted-foreground">Teléfono</p>
                  <p>{{ pedido.cliente_telefono }}</p>
                </div>
                <div class="min-w-0">
                  <p class="text-muted-foreground">Correo</p>
                  <p class="truncate">{{ pedido.cliente_correo ?? '—' }}</p>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle class="text-base">Artículos</CardTitle>
              </CardHeader>
              <CardContent class="p-0">
                <div class="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead class="w-16">Cant.</TableHead>
                        <TableHead>Descripción</TableHead>
                        <TableHead class="w-28 text-right">P. unitario</TableHead>
                        <TableHead class="w-28 text-right">Importe</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      <TableRow v-for="linea in pedido.lineas" :key="linea.id">
                        <TableCell>{{ linea.cantidad }}</TableCell>
                        <TableCell class="min-w-0">
                          {{ linea.descripcion }}
                          <Badge v-if="linea.es_libre" variant="secondary" class="ml-2">
                            Línea libre
                          </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                          ${{ linea.precio_unitario.toFixed(2) }}
                        </TableCell>
                        <TableCell class="text-right">
                          ${{ (linea.importe + linea.iva_importe).toFixed(2) }}
                        </TableCell>
                      </TableRow>
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader class="flex flex-row items-center justify-between">
                <CardTitle class="text-base">Pagos</CardTitle>
                <Button
                  v-if="pedido.saldo_pendiente > 0"
                  size="sm"
                  variant="outline"
                  @click="abrirPago"
                >
                  Agregar pago
                </Button>
              </CardHeader>
              <CardContent class="p-0">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Fecha</TableHead>
                      <TableHead>Cuenta</TableHead>
                      <TableHead class="text-right">Monto</TableHead>
                      <TableHead class="w-10" />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    <TableRow v-if="pedido.pagos.length === 0">
                      <TableCell colspan="4" class="text-muted-foreground py-6 text-center">
                        Todavía no se ha registrado ningún pago.
                      </TableCell>
                    </TableRow>
                    <TableRow v-for="pago in pedido.pagos" :key="pago.id">
                      <TableCell>{{ pago.fecha_pago }}</TableCell>
                      <TableCell>
                        {{ pago.cuenta_nombre ?? '—' }}
                        <!-- El pago que cerró la venta al escanear el QR. Si quedó mal capturado,
                             se corrige borrándolo y recapturándolo. -->
                        <Badge v-if="pago.registrado_al_entregar" variant="secondary" class="ml-2">
                          Al entregar
                        </Badge>
                      </TableCell>
                      <TableCell class="text-right">${{ pago.monto.toFixed(2) }}</TableCell>
                      <TableCell>
                        <Button
                          v-if="!pedido.factura_id"
                          variant="ghost"
                          size="icon-sm"
                          @click="eliminarPago(pago.id)"
                        >
                          <TrashIcon class="size-4" />
                          <span class="sr-only">Eliminar pago</span>
                        </Button>
                      </TableCell>
                    </TableRow>
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          </div>

          <div class="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle class="text-base">Totales</CardTitle>
              </CardHeader>
              <CardContent class="space-y-1 text-sm">
                <div class="flex justify-between">
                  <span class="text-muted-foreground">Subtotal</span>
                  <span>${{ pedido.subtotal.toFixed(2) }}</span>
                </div>
                <div v-if="pedido.total_descuento > 0" class="flex justify-between">
                  <span class="text-muted-foreground">Descuento</span>
                  <span>-${{ pedido.total_descuento.toFixed(2) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-muted-foreground">IVA</span>
                  <span>${{ pedido.total_iva_16.toFixed(2) }}</span>
                </div>
                <div class="flex justify-between font-semibold">
                  <span>Total</span>
                  <span>${{ pedido.total.toFixed(2) }}</span>
                </div>
                <hr class="my-2" />
                <div class="flex justify-between">
                  <span class="text-muted-foreground">Pagado</span>
                  <span>${{ pedido.total_pagado.toFixed(2) }}</span>
                </div>
                <div class="flex justify-between font-semibold">
                  <span>Saldo pendiente</span>
                  <span>${{ pedido.saldo_pendiente.toFixed(2) }}</span>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle class="text-base">Ticket</CardTitle>
              </CardHeader>
              <CardContent>
                <img
                  v-if="ticketUrl"
                  :src="ticketUrl"
                  alt="Ticket de compra"
                  class="mx-auto max-w-full rounded border"
                />
                <p v-else class="text-muted-foreground text-sm">
                  No se pudo generar la vista previa del ticket.
                </p>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle class="text-base">Autofactura</CardTitle>
              </CardHeader>
              <CardContent class="space-y-3 text-sm">
                <Alert v-if="pedido.autofactura_error" variant="destructive">
                  <AlertDescription>
                    El cliente intentó facturar y falló: {{ pedido.autofactura_error }}
                  </AlertDescription>
                </Alert>

                <template v-if="pedido.factura_id">
                  <p class="text-muted-foreground">Este pedido ya se facturó.</p>
                  <Button as-child variant="outline" size="sm">
                    <RouterLink
                      :to="{ name: 'facturas-detalle', params: { id: pedido.factura_id } }"
                    >
                      <DocumentTextIcon class="size-4" />
                      Ver factura
                    </RouterLink>
                  </Button>
                </template>

                <template v-else-if="pedido.autofactura_no_disponible">
                  <p class="text-muted-foreground">{{ pedido.autofactura_no_disponible }}</p>
                </template>

                <template v-else>
                  <p class="text-muted-foreground">
                    El cliente captura sus datos fiscales en este enlace y el sistema le timbra la
                    factura. Vence al cerrar el mes de la venta.
                  </p>
                  <div class="flex flex-wrap gap-2">
                    <Button size="sm" @click="compartirAutofactura">
                      <ShareIcon class="size-4" />
                      Enviar por WhatsApp
                    </Button>
                    <Button size="sm" variant="outline" @click="copiarEnlace">Copiar enlace</Button>
                  </div>
                </template>
              </CardContent>
            </Card>
          </div>
        </div>
      </template>

      <Dialog v-model:open="dialogoPago">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Registrar pago</DialogTitle>
            <DialogDescription>
              El monto entra a la cuenta elegida como un movimiento de ingreso en Tesorería.
            </DialogDescription>
          </DialogHeader>

          <div class="space-y-4">
            <div class="space-y-1.5">
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
            <div class="space-y-1.5">
              <Label for="fecha">Fecha</Label>
              <Input id="fecha" v-model="fechaPago" type="date" />
            </div>
            <div class="space-y-1.5">
              <Label>Cuenta</Label>
              <CuentaSelect v-model="cuentaPago" />
            </div>
            <Alert v-if="errorPago" variant="destructive">
              <AlertDescription>{{ errorPago }}</AlertDescription>
            </Alert>
          </div>

          <DialogFooter>
            <Button variant="outline" :disabled="guardandoPago" @click="dialogoPago = false">
              Cancelar
            </Button>
            <Button
              :disabled="guardandoPago || montoPago === null || cuentaPago === null"
              @click="guardarPago"
            >
              {{ guardandoPago ? 'Guardando...' : 'Registrar pago' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
