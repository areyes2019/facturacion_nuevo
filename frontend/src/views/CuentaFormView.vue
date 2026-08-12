<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  useCuentasStore,
  TIPOS_CUENTA,
  type CuentaPayload,
  type TipoCuenta,
} from '../stores/cuentas'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '../components/ui/select'
import AppLayout from '../layouts/AppLayout.vue'

const route = useRoute()
const router = useRouter()
const cuentas = useCuentasStore()

const cuentaId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => cuentaId.value !== null)

const form = reactive({
  nombre: '',
  tipo: null as TipoCuenta | null,
  saldo_inicial: '0' as string,
  activa: true,
})

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

onMounted(async () => {
  if (!cuentaId.value) return

  cargando.value = true
  try {
    const cuenta = await cuentas.fetchOne(cuentaId.value)
    form.nombre = cuenta.nombre
    form.tipo = cuenta.tipo
    form.saldo_inicial = cuenta.saldo_inicial.toString()
    form.activa = cuenta.activa
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

async function onSubmit() {
  guardando.value = true
  errorGeneral.value = null
  erroresPorCampo.value = {}

  const payload: CuentaPayload = {
    nombre: form.nombre,
    tipo: form.tipo,
    activa: form.activa,
  }

  try {
    if (esEdicion.value && cuentaId.value) {
      // `saldo_inicial` no se envía: es inmutable tras la creación y el backend lo ignoraría.
      await cuentas.update(cuentaId.value, payload)
    } else {
      await cuentas.create({
        ...payload,
        saldo_inicial: form.saldo_inicial ? parseFloat(form.saldo_inicial) : 0,
      })
    }

    await router.push({ name: 'cuentas' })
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
    <div class="mx-auto max-w-2xl space-y-4">
      <h1 class="font-heading text-foreground text-xl font-semibold">
        {{ esEdicion ? 'Editar cuenta' : 'Nueva cuenta' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos de la cuenta</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label for="nombre">Nombre</Label>
              <Input id="nombre" v-model="form.nombre" required />
              <p v-if="erroresPorCampo.nombre" class="text-destructive text-sm">
                {{ erroresPorCampo.nombre }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Tipo</Label>
              <Select
                :model-value="form.tipo ?? undefined"
                @update:model-value="(v) => (form.tipo = (v as TipoCuenta) || null)"
              >
                <SelectTrigger class="w-full">
                  <SelectValue placeholder="Selecciona un tipo" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="tipo in TIPOS_CUENTA" :key="tipo.id" :value="tipo.id">
                    {{ tipo.texto }}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p v-if="erroresPorCampo.tipo" class="text-destructive text-sm">
                {{ erroresPorCampo.tipo }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="saldo_inicial">Saldo inicial</Label>
              <Input
                id="saldo_inicial"
                v-model="form.saldo_inicial"
                type="number"
                min="0"
                step="0.01"
                :disabled="esEdicion"
              />
              <p v-if="esEdicion" class="text-muted-foreground text-sm">
                El saldo inicial no se puede cambiar después de crear la cuenta. Para corregirlo,
                registra un ajuste en Movimientos: así queda rastro en el historial.
              </p>
              <p v-if="erroresPorCampo.saldo_inicial" class="text-destructive text-sm">
                {{ erroresPorCampo.saldo_inicial }}
              </p>
            </div>

            <div v-if="esEdicion" class="flex items-center gap-2">
              <input id="activa" v-model="form.activa" type="checkbox" class="size-4" />
              <Label for="activa">Cuenta activa</Label>
            </div>
            <p v-if="esEdicion && !form.activa" class="text-muted-foreground text-sm">
              Una cuenta inactiva no admite movimientos nuevos, pero conserva su historial y su
              saldo sigue apareciendo en la consulta de saldos.
            </p>
          </CardContent>
        </Card>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'cuentas' })">
            Cancelar
          </Button>
          <Button type="submit" :disabled="guardando">
            {{ guardando ? 'Guardando...' : 'Guardar' }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
