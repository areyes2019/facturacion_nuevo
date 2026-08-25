<script setup lang="ts">
/**
 * Formulario de "Enviar a domicilio", compartido entre la Orden de Trabajo (038) y el envío directo
 * de Cotización de distribuidor (041-envio-domicilio-direccion-y-distribuidor.md): mismos campos en
 * ambos casos, incluida la dirección.
 */
import { computed, onMounted, ref, watch } from 'vue'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../ui/dialog'
import { Button } from '../ui/button'
import { Input } from '../ui/input'
import { Label } from '../ui/label'
import { Alert, AlertDescription } from '../ui/alert'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../ui/select'
import CuentaSelect from '../CuentaSelect.vue'
import { useConfiguracionStore } from '../../stores/configuracion'
import { extractErrorMessage, extractFieldErrors } from '../../lib/errors'
import type { EnvioPayload, TarifaEnvio, FormaPagoEnvio } from '../../stores/ordenesTrabajo'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  guardar: (payload: EnvioPayload) => Promise<void>
}>()

const configuracion = useConfiguracionStore()
onMounted(() => {
  configuracion.fetch().catch(() => {})
})

const guardando = ref(false)
const error = ref<string | null>(null)
const errores = ref<Record<string, string>>({})

function formVacio() {
  return {
    nombre_receptor: '',
    telefono_receptor: '',
    direccion: '',
    fecha_recepcion: new Date().toISOString().slice(0, 10),
    hora_recepcion: '',
    tarifa: 'a' as TarifaEnvio,
    forma_pago: 'por_cobrar' as FormaPagoEnvio,
    cuenta_id: null as number | null,
  }
}

const form = ref(formVacio())

watch(open, (abierto) => {
  if (!abierto) return
  error.value = null
  errores.value = {}
  form.value = formVacio()
})

const tarifaMonto = computed(() => {
  const claves = { a: 'envio_tarifa_a', b: 'envio_tarifa_b', c: 'envio_tarifa_c' } as const
  const valores = configuracion.valores
  if (!valores) return null
  return Number(valores[claves[form.value.tarifa]])
})

async function onGuardar() {
  guardando.value = true
  error.value = null
  errores.value = {}

  try {
    await props.guardar({
      nombre_receptor: form.value.nombre_receptor,
      telefono_receptor: form.value.telefono_receptor,
      direccion: form.value.direccion,
      fecha_recepcion: form.value.fecha_recepcion,
      hora_recepcion: form.value.hora_recepcion,
      tarifa: form.value.tarifa,
      forma_pago: form.value.forma_pago,
      ...(form.value.forma_pago === 'prepagado' && form.value.cuenta_id
        ? { cuenta_id: form.value.cuenta_id }
        : {}),
    })
    open.value = false
  } catch (err) {
    errores.value = extractFieldErrors(err)
    error.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Enviar a domicilio</DialogTitle>
        <DialogDescription>Datos de quien recibe y de la entrega.</DialogDescription>
      </DialogHeader>

      <div class="space-y-4">
        <Alert v-if="error" variant="destructive">
          <AlertDescription>{{ error }}</AlertDescription>
        </Alert>

        <div class="space-y-1.5">
          <Label>Nombre de quien recibe</Label>
          <Input v-model="form.nombre_receptor" />
          <p v-if="errores.nombre_receptor" class="text-destructive text-sm">
            {{ errores.nombre_receptor }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label>Teléfono de quien recibe</Label>
          <Input v-model="form.telefono_receptor" />
          <p v-if="errores.telefono_receptor" class="text-destructive text-sm">
            {{ errores.telefono_receptor }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label>Dirección</Label>
          <Input v-model="form.direccion" placeholder="Calle, número, colonia..." />
          <p v-if="errores.direccion" class="text-destructive text-sm">
            {{ errores.direccion }}
          </p>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <Label>Fecha de recepción</Label>
            <Input v-model="form.fecha_recepcion" type="date" />
          </div>
          <div class="space-y-1.5">
            <Label>Hora de recepción</Label>
            <Input v-model="form.hora_recepcion" type="time" />
            <p v-if="errores.hora_recepcion" class="text-destructive text-sm">
              {{ errores.hora_recepcion }}
            </p>
          </div>
        </div>

        <div class="space-y-1.5">
          <Label>Tarifa</Label>
          <Select v-model="form.tarifa">
            <SelectTrigger class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="a">Tarifa A</SelectItem>
              <SelectItem value="b">Tarifa B</SelectItem>
              <SelectItem value="c">Tarifa C</SelectItem>
            </SelectContent>
          </Select>
          <p v-if="tarifaMonto !== null" class="text-muted-foreground text-sm">
            Monto: ${{ tarifaMonto.toFixed(2) }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label>Estado del pago del envío</Label>
          <Select v-model="form.forma_pago">
            <SelectTrigger class="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="por_cobrar">Por cobrar (lo cobra el repartidor)</SelectItem>
              <SelectItem value="prepagado">Prepagado (ya se cobró)</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-if="form.forma_pago === 'prepagado'" class="space-y-1.5">
          <Label>¿A qué cuenta entra el dinero?</Label>
          <CuentaSelect v-model="form.cuenta_id" />
          <p v-if="errores.cuenta_id" class="text-destructive text-sm">
            {{ errores.cuenta_id }}
          </p>
        </div>
      </div>

      <DialogFooter>
        <Button variant="outline" @click="open = false">Cancelar</Button>
        <Button :disabled="guardando" @click="onGuardar">
          {{ guardando ? 'Guardando...' : 'Guardar envío' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
