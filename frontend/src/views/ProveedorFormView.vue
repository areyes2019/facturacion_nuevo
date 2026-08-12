<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useProveedoresStore, type ProveedorPayload } from '../stores/proveedores'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import AppLayout from '../layouts/AppLayout.vue'

const route = useRoute()
const router = useRouter()
const proveedores = useProveedoresStore()

const proveedorId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => proveedorId.value !== null)

const form = reactive({
  nombre_comercial: '',
  nombre_contacto: '',
  correo: '',
  telefono: '',
  rfc: '',
})

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

onMounted(async () => {
  if (!proveedorId.value) return

  cargando.value = true
  try {
    const proveedor = await proveedores.fetchOne(proveedorId.value)
    form.nombre_comercial = proveedor.nombre_comercial
    form.nombre_contacto = proveedor.nombre_contacto ?? ''
    form.correo = proveedor.correo ?? ''
    form.telefono = proveedor.telefono ?? ''
    form.rfc = proveedor.rfc ?? ''
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

  const payload: ProveedorPayload = {
    nombre_comercial: form.nombre_comercial,
    nombre_contacto: form.nombre_contacto || null,
    correo: form.correo || null,
    telefono: form.telefono || null,
    rfc: form.rfc || null,
  }

  try {
    if (esEdicion.value && proveedorId.value) {
      await proveedores.update(proveedorId.value, payload)
    } else {
      await proveedores.create(payload)
    }
    await router.push({ name: 'proveedores' })
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
        {{ esEdicion ? 'Editar proveedor' : 'Nuevo proveedor' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos del proveedor</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label for="nombre_comercial">Nombre comercial</Label>
              <Input id="nombre_comercial" v-model="form.nombre_comercial" required />
              <p v-if="erroresPorCampo.nombre_comercial" class="text-destructive text-sm">
                {{ erroresPorCampo.nombre_comercial }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="nombre_contacto">Nombre de contacto</Label>
              <Input id="nombre_contacto" v-model="form.nombre_contacto" />
              <p v-if="erroresPorCampo.nombre_contacto" class="text-destructive text-sm">
                {{ erroresPorCampo.nombre_contacto }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="correo">Correo</Label>
              <Input id="correo" v-model="form.correo" type="email" />
              <p v-if="erroresPorCampo.correo" class="text-destructive text-sm">
                {{ erroresPorCampo.correo }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="telefono">Teléfono</Label>
              <Input id="telefono" v-model="form.telefono" placeholder="10 dígitos" />
              <p v-if="erroresPorCampo.telefono" class="text-destructive text-sm">
                {{ erroresPorCampo.telefono }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="rfc">RFC</Label>
              <Input id="rfc" v-model="form.rfc" maxlength="13" />
              <p v-if="erroresPorCampo.rfc" class="text-destructive text-sm">
                {{ erroresPorCampo.rfc }}
              </p>
            </div>
          </CardContent>
        </Card>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'proveedores' })">
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
