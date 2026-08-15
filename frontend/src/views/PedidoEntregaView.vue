<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { CheckCircleIcon, ArrowUturnLeftIcon } from '@heroicons/vue/24/outline'
import { usePedidosStore, type Pedido, type ResultadoEntrega } from '../stores/pedidos'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Badge } from '../components/ui/badge'

/**
 * Destino del QR de la etiqueta (ver 027-venta-mostrador-ticket.md).
 *
 * Al abrirse **cobra el saldo y marca entregado, sin pedir confirmación**. El cliente está enfrente
 * esperando su sello y detener el mostrador para pulsar un botón no aporta nada. Lo que sí aporta
 * es el "Deshacer" de 10 segundos, por si se escaneó la etiqueta equivocada.
 */
const SEGUNDOS_PARA_DESHACER = 10

const route = useRoute()
const pedidos = usePedidosStore()

const procesando = ref(true)
const resultado = ref<ResultadoEntrega | null>(null)
const pedido = ref<Pedido | null>(null)
const error = ref<string | null>(null)

const segundosRestantes = ref(0)
const deshaciendo = ref(false)
const deshecho = ref(false)

let cuentaRegresiva: ReturnType<typeof setInterval> | undefined

onMounted(async () => {
  try {
    const respuesta = await pedidos.entregar(Number(route.params.id))
    resultado.value = respuesta
    pedido.value = respuesta.pedido

    // El segundo escaneo no cobró nada y no hay nada que deshacer: ofrecer el botón invitaría a
    // revertir una entrega hecha hace horas.
    if (!respuesta.ya_estaba_entregado) {
      iniciarCuentaRegresiva()
    }
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    procesando.value = false
  }
})

onBeforeUnmount(() => clearInterval(cuentaRegresiva))

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
  <AppLayout>
    <div class="mx-auto max-w-md space-y-4">
      <p v-if="procesando" class="text-muted-foreground text-center">Registrando la entrega...</p>

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

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
              Se borró el cobro automático y se devolvió el saldo de la cuenta. El pedido quedó como
              estaba.
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
            <p v-else-if="!resultado.aviso" class="text-muted-foreground text-sm">
              El pedido ya estaba pagado por completo.
            </p>

            <Alert v-if="resultado.aviso" variant="destructive">
              <AlertDescription>{{ resultado.aviso }}</AlertDescription>
            </Alert>

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

          <Badge v-if="pedido.saldo_pendiente > 0" variant="destructive">
            Queda saldo por cobrar
          </Badge>

          <Button as-child variant="ghost" class="w-full">
            <RouterLink :to="{ name: 'pedidos-detalle', params: { id: pedido.id } }">
              Ver el pedido completo
            </RouterLink>
          </Button>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
