<script setup lang="ts">
import { onMounted, ref } from 'vue'
import http from '../../lib/http'
import { mensajeDeFalla } from '../../lib/errors'
import type { Cuenta } from '../../stores/cuentas'
import { Alert, AlertDescription } from '../ui/alert'
import { Button } from '../ui/button'
import { Label } from '../ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select'

/**
 * ¿A qué cuenta entra el dinero? La misma pregunta en el cobro de la venta al público y en el pago
 * de una cotización desde el mostrador (ver 029-pwa-mostrador.md y 031-mostrador-consulta.md).
 *
 * **La caja viene preseleccionada** —la cuenta de efectivo activa más antigua— y se puede cambiar
 * con un toque. Es al revés que la pantalla de entrega de 027, donde la cuenta se elige siempre, y
 * es una diferencia buscada: allá se cierra un pedido que pudo pagarse por transferencia días
 * antes, acá se cobra lo que está ocurriendo enfrente, donde la caja no es una comodidad que invite
 * a confirmar por inercia, es lo que pasa casi siempre.
 */

withDefaults(defineProps<{ etiqueta?: string }>(), {
  etiqueta: '¿A qué cuenta entra el dinero?',
})

const cuentaId = defineModel<number | null>({ required: true })

const cuentas = ref<Cuenta[]>([])
const error = ref<string | null>(null)

onMounted(cargar)

async function cargar() {
  error.value = null

  try {
    const { data } = await http.get('/cuentas', { params: { per_page: 100, activa: 'true' } })
    cuentas.value = (data.data as Cuenta[]).slice().sort((a, b) => a.id - b.id)
    cuentaId.value =
      (cuentas.value.find((c) => c.tipo === 'efectivo') ?? cuentas.value[0])?.id ?? null
  } catch (err) {
    error.value = mensajeDeFalla(err)
  }
}
</script>

<template>
  <div class="space-y-1.5">
    <Label>{{ etiqueta }}</Label>
    <Select
      :model-value="cuentaId?.toString() ?? undefined"
      @update:model-value="(v) => (cuentaId = v ? Number(v) : null)"
    >
      <SelectTrigger class="h-12 w-full">
        <SelectValue placeholder="Selecciona una cuenta" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem v-for="cuenta in cuentas" :key="cuenta.id" :value="cuenta.id.toString()">
          {{ cuenta.nombre }}
        </SelectItem>
      </SelectContent>
    </Select>

    <Alert v-if="error" variant="destructive">
      <AlertDescription class="space-y-2">
        <p>{{ error }}</p>
        <Button type="button" size="sm" variant="outline" @click="cargar">Reintentar</Button>
      </AlertDescription>
    </Alert>
  </div>
</template>
