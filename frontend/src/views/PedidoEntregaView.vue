<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { CheckCircleIcon, ArrowUturnLeftIcon, BanknotesIcon } from '@heroicons/vue/24/outline'
import { usePedidosStore, type Pedido, type ResultadoEntrega } from '../stores/pedidos'
import { extractErrorMessage } from '../lib/errors'
import { enModoMostrador } from '../lib/modoMostrador'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Label } from '../components/ui/label'
import CuentaSelect from '../components/CuentaSelect.vue'

/**
 * Destino del QR, el de la etiqueta y el del ticket (ver 027-venta-mostrador-ticket.md).
 *
 * **Se resuelve sola según el saldo**, en tres caminos:
 *
 * - **Ya entregado**: solo informa de quién era y cuándo. No ofrece nada.
 * - **Saldo en cero**: cierra el pedido al montarse, sin preguntar — no hay nada que decidir, y
 *   preguntar sería pedir permiso para lo único que se puede hacer. Es el único camino donde el
 *   sistema actúa por su cuenta, y por eso el único con "Deshacer".
 * - **Saldo pendiente**: no toca nada hasta que el usuario confirma. Ver a quién se le está
 *   cobrando antes de cobrarle atrapa la etiqueta equivocada cuando corregirlo todavía es gratis.
 *
 * Está pensada para el pulgar y para leerse de lejos: es la única pantalla del sistema que se usa
 * de pie, con el cliente enfrente y una caja en la otra mano.
 */
const SEGUNDOS_PARA_DESHACER = 10

const route = useRoute()
const pedidos = usePedidosStore()

/**
 * En el mostrador se entregan varios trabajos seguidos: el pie cambia a "Escanear otra" e "Inicio",
 * porque volver al inicio entre uno y otro son dos toques por paquete, y el detalle del pedido es
 * una pantalla que el candado de 029 no permite.
 */
const mostrador = enModoMostrador()

const cargando = ref(true)
const confirmando = ref(false)
const resultado = ref<ResultadoEntrega | null>(null)
const pedido = ref<Pedido | null>(null)
const error = ref<string | null>(null)

const cuenta = ref<number | null>(null)

const segundosRestantes = ref(0)
const deshaciendo = ref(false)
const deshecho = ref(false)

let cuentaRegresiva: ReturnType<typeof setInterval> | undefined

/**
 * Mientras no haya resultado y quede saldo por cobrar en un pedido abierto, lo que toca es
 * confirmar. Se comprueba también el estado porque un pedido ya entregado nunca pide nada, aunque
 * arrastre saldo: ahí lo único que queda es informar cuándo se entregó.
 */
const pideConfirmacion = computed(
  () =>
    resultado.value === null &&
    pedido.value !== null &&
    pedido.value.estado !== 'entregado' &&
    pedido.value.saldo_pendiente > 0,
)

const saldo = computed(() => pedido.value?.saldo_pendiente ?? 0)

onMounted(async () => {
  try {
    pedido.value = await pedidos.fetchOne(Number(route.params.id))

    // El pedido ya pagado no tiene nada que preguntar: se cierra al llegar. El ya entregado
    // tampoco, y el backend responde que no tocó nada y con qué fecha se cerró.
    if (pedido.value.estado === 'entregado' || pedido.value.saldo_pendiente <= 0) {
      await cerrar()
    }
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

onBeforeUnmount(() => clearInterval(cuentaRegresiva))

/**
 * Cierra el pedido. Con saldo manda la cuenta elegida; sin saldo, ninguna — el backend rechaza la
 * cuenta en ese caso, porque recibirla solo podría significar que alguien entendió mal.
 */
async function cerrar() {
  if (!pedido.value) return

  confirmando.value = true
  error.value = null

  try {
    const respuesta = await pedidos.entregar(pedido.value.id, cuenta.value ?? undefined)
    resultado.value = respuesta
    pedido.value = respuesta.pedido

    // "Deshacer" solo donde el sistema actuó sin preguntar. Tras una confirmación no hace falta, y
    // el backend además rechaza deshacer una entrega que cobró.
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
  if (!pedido.value) return

  deshaciendo.value = true
  error.value = null

  try {
    pedido.value = await pedidos.deshacerEntrega(pedido.value.id)
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
      <p v-if="cargando" class="text-muted-foreground text-center">Buscando el pedido...</p>

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <!-- Camino 3: hay saldo y nada se toca hasta confirmar. -->
      <Card v-if="pideConfirmacion && pedido">
        <CardHeader class="items-center text-center">
          <BanknotesIcon class="text-primary size-12" />
          <CardTitle>Cobrar y entregar</CardTitle>
        </CardHeader>

        <CardContent class="space-y-5">
          <div class="text-center">
            <p class="font-mono text-2xl font-semibold">{{ pedido.numero_ticket }}</p>
            <p class="text-lg">{{ pedido.cliente_nombre }}</p>
            <p class="text-muted-foreground text-sm">{{ pedido.cliente_telefono }}</p>
          </div>

          <div class="flex flex-col gap-1">
            <div class="flex justify-between text-sm">
              <span class="text-muted-foreground">Total</span>
              <span>${{ pedido.total.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between text-sm">
              <span class="text-muted-foreground">Pagado</span>
              <span>${{ pedido.total_pagado.toFixed(2) }}</span>
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
            Se cobra el saldo completo y el pedido queda cerrado.
          </p>
        </CardContent>
      </Card>

      <!-- Caminos 1 y 2, y el acuse de los tres. -->
      <Card v-if="resultado && pedido">
        <CardHeader class="items-center text-center">
          <CheckCircleIcon v-if="!deshecho" class="text-primary size-12" />
          <CardTitle>
            <template v-if="deshecho">Entrega deshecha</template>
            <template v-else-if="resultado.ya_estaba_entregado">Este pedido ya se entregó</template>
            <template v-else>Pedido entregado</template>
          </CardTitle>
        </CardHeader>

        <CardContent class="space-y-4 text-center">
          <div>
            <p class="font-mono text-2xl font-semibold">{{ pedido.numero_ticket }}</p>
            <p class="text-muted-foreground">{{ pedido.cliente_nombre }}</p>
          </div>

          <template v-if="deshecho">
            <p class="text-muted-foreground text-sm">
              El pedido quedó como estaba. No se movió ningún pago.
            </p>
          </template>

          <template v-else-if="resultado.ya_estaba_entregado">
            <p class="text-muted-foreground text-sm">
              Se entregó el {{ new Date(pedido.entregado_en ?? '').toLocaleString() }}. No se cobró
              nada de nuevo.
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
              El pedido ya estaba pagado por completo.
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
              <span>${{ pedido.total.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-muted-foreground">Pagado</span>
              <span>${{ pedido.total_pagado.toFixed(2) }}</span>
            </div>
            <div class="flex justify-between font-semibold">
              <span>Saldo</span>
              <span>${{ pedido.saldo_pendiente.toFixed(2) }}</span>
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
            <RouterLink :to="{ name: 'pedidos-detalle', params: { id: pedido.id } }">
              Ver el pedido completo
            </RouterLink>
          </Button>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
