<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { CheckCircleIcon, ArrowUturnLeftIcon, BanknotesIcon } from '@heroicons/vue/24/outline'
import {
  useCotizacionesStore,
  type Cotizacion,
  type ResultadoEntregaCotizacion,
} from '../stores/cotizaciones'
import { extractErrorMessage } from '../lib/errors'
import { enModoMostrador } from '../lib/modoMostrador'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Label } from '../components/ui/label'
import CuentaSelect from '../components/CuentaSelect.vue'

/**
 * Destino del QR de la cotización, mismo mecanismo que `PedidoEntregaView` (027, extendido en 038).
 *
 * Tres caminos, igual que Pedido: ya entregada (solo informa), saldo en cero (cierra sola, con
 * "Deshacer"), saldo pendiente (pide confirmar cobro y cuenta antes de cerrar).
 */
const SEGUNDOS_PARA_DESHACER = 10

const route = useRoute()
const cotizaciones = useCotizacionesStore()

/**
 * Mismo criterio que `PedidoEntregaView` (027): en el mostrador se entregan varios trabajos
 * seguidos, así que el pie cambia a "Escanear otra" e "Inicio".
 */
const mostrador = enModoMostrador()

const cargando = ref(true)
const confirmando = ref(false)
const resultado = ref<ResultadoEntregaCotizacion | null>(null)
const cotizacion = ref<Cotizacion | null>(null)
const error = ref<string | null>(null)

const cuenta = ref<number | null>(null)

const segundosRestantes = ref(0)
const deshaciendo = ref(false)
const deshecho = ref(false)

let cuentaRegresiva: ReturnType<typeof setInterval> | undefined

const pideConfirmacion = computed(
  () =>
    resultado.value === null &&
    cotizacion.value !== null &&
    cotizacion.value.estado !== 'producto_entregado' &&
    cotizacion.value.saldo_pendiente > 0,
)

const saldo = computed(() => cotizacion.value?.saldo_pendiente ?? 0)

onMounted(async () => {
  try {
    cotizacion.value = await cotizaciones.fetchOne(Number(route.params.id))

    if (cotizacion.value.estado === 'producto_entregado' || cotizacion.value.saldo_pendiente <= 0) {
      await cerrar()
    }
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

onBeforeUnmount(() => clearInterval(cuentaRegresiva))

async function cerrar() {
  if (!cotizacion.value) return

  confirmando.value = true
  error.value = null

  try {
    const respuesta = await cotizaciones.entregar(cotizacion.value.id, cuenta.value ?? undefined)
    resultado.value = respuesta
    cotizacion.value = respuesta.cotizacion

    if (!respuesta.ya_estaba_entregado && respuesta.cobrado === 0) {
      iniciarCuentaRegresiva()
    }
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    confirmando.value = false
  }
}

function iniciarCuentaRegresiva() {
  segundosRestantes.value = SEGUNDOS_PARA_DESHACER
  cuentaRegresiva = setInterval(() => {
    segundosRestantes.value -= 1
    if (segundosRestantes.value <= 0) clearInterval(cuentaRegresiva)
  }, 1000)
}

async function deshacer() {
  if (!cotizacion.value) return

  deshaciendo.value = true
  error.value = null

  try {
    cotizacion.value = await cotizaciones.deshacerEntrega(cotizacion.value.id)
    deshecho.value = true
    clearInterval(cuentaRegresiva)
    segundosRestantes.value = 0
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    deshaciendo.value = false
  }
}
</script>

<template>
  <AppLayout :mostrador="mostrador">
    <div class="mx-auto max-w-md space-y-4">
      <p v-if="cargando" class="text-muted-foreground text-center">Buscando la cotización...</p>

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <Card v-if="pideConfirmacion && cotizacion">
        <CardHeader class="items-center text-center">
          <BanknotesIcon class="text-primary size-12" />
          <CardTitle>Cobrar y entregar</CardTitle>
        </CardHeader>

        <CardContent class="space-y-5">
          <div class="text-center">
            <p class="font-mono text-2xl font-semibold">COT-{{ cotizacion.folio }}</p>
            <p class="text-lg">{{ cotizacion.cliente_razon_social }}</p>
            <p class="text-muted-foreground text-sm">{{ cotizacion.cliente_telefono }}</p>
          </div>

          <div class="flex flex-col gap-1">
            <div class="flex justify-between text-sm">
              <span class="text-muted-foreground">Total</span>
              <span>${{ cotizacion.total.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between text-sm">
              <span class="text-muted-foreground">Pagado</span>
              <span>${{ cotizacion.total_pagado.toFixed(2) }}</span>
            </div>
            <div class="flex items-baseline justify-between border-t pt-2 text-2xl font-semibold">
              <span>Saldo</span>
              <span>${{ saldo.toFixed(2) }}</span>
            </div>
          </div>

          <div class="space-y-1.5">
            <Label>¿A qué cuenta entra el dinero?</Label>
            <CuentaSelect v-model="cuenta" />
          </div>

          <Button class="h-14 w-full text-base" :disabled="confirmando || !cuenta" @click="cerrar">
            {{ confirmando ? 'Registrando...' : `Cobrar $${saldo.toFixed(2)} y entregar` }}
          </Button>

          <p class="text-muted-foreground text-center text-xs">
            Se cobra el saldo completo y la cotización queda cerrada.
          </p>
        </CardContent>
      </Card>

      <Card v-if="resultado && cotizacion">
        <CardHeader class="items-center text-center">
          <CheckCircleIcon v-if="!deshecho" class="text-primary size-12" />
          <CardTitle>
            <template v-if="deshecho">Entrega deshecha</template>
            <template v-else-if="resultado.ya_estaba_entregado">
              Esta cotización ya se entregó
            </template>
            <template v-else>Cotización entregada</template>
          </CardTitle>
        </CardHeader>

        <CardContent class="space-y-4 text-center">
          <div>
            <p class="font-mono text-2xl font-semibold">COT-{{ cotizacion.folio }}</p>
            <p class="text-muted-foreground">{{ cotizacion.cliente_razon_social }}</p>
          </div>

          <template v-if="deshecho">
            <p class="text-muted-foreground text-sm">
              La cotización quedó como estaba. No se movió ningún pago.
            </p>
          </template>

          <template v-else-if="resultado.ya_estaba_entregado">
            <p class="text-muted-foreground text-sm">
              Se entregó el {{ new Date(cotizacion.entregado_en ?? '').toLocaleString() }}. No se
              cobró nada de nuevo.
            </p>
          </template>

          <template v-else>
            <div v-if="resultado.cobrado > 0" class="space-y-1">
              <p class="text-lg font-semibold">Se cobró ${{ resultado.cobrado.toFixed(2) }}</p>
              <p class="text-muted-foreground text-sm">
                Registrado en <strong>{{ resultado.cuenta_nombre }}</strong>
              </p>
            </div>
            <p v-else class="text-muted-foreground text-sm">
              La cotización ya estaba pagada por completo.
            </p>

            <Button
              v-if="segundosRestantes > 0"
              variant="outline"
              class="w-full"
              :disabled="deshaciendo"
              @click="deshacer"
            >
              <ArrowUturnLeftIcon class="size-4" />
              Deshacer ({{ segundosRestantes }})
            </Button>
          </template>

          <div class="flex flex-col gap-1 text-sm">
            <div class="flex justify-between">
              <span class="text-muted-foreground">Total</span>
              <span>${{ cotizacion.total.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Pagado</span>
              <span>${{ cotizacion.total_pagado.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between font-semibold">
              <span>Saldo</span>
              <span>${{ cotizacion.saldo_pendiente.toFixed(2) }}</span>
            </div>
          </div>

          <div v-if="mostrador" class="flex gap-2">
            <Button as-child variant="outline" class="h-12 flex-1">
              <RouterLink :to="{ name: 'mostrador-escanear' }">Escanear otra</RouterLink>
            </Button>
            <Button as-child variant="ghost" class="h-12 flex-1">
              <RouterLink :to="{ name: 'dashboard' }">Inicio</RouterLink>
            </Button>
          </div>

          <Button v-else as-child variant="ghost" class="w-full">
            <RouterLink :to="{ name: 'cotizaciones-detalle', params: { id: cotizacion.id } }">
              Ver la cotización completa
            </RouterLink>
          </Button>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
