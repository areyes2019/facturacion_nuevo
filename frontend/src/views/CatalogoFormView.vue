<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCatalogosStore, type CatalogoPayload } from '../stores/catalogos'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import ProveedorSelect from '../components/ProveedorSelect.vue'
import AppLayout from '../layouts/AppLayout.vue'

const route = useRoute()
const router = useRouter()
const catalogos = useCatalogosStore()

const catalogoId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => catalogoId.value !== null)

const form = reactive({
  proveedor_id: null as number | null,
  nombre: '',
  descuento: '0' as string,
  utilidad_porcentaje: '0' as string,
})

// Aviso no bloqueante por encima del 200% (ver 011-precio-proveedor-utilidad.md): a partir de ahí
// es más probable un dedazo que un markup real, pero el markup alto es legítimo y sí se guarda.
const UMBRAL_PORCENTAJE_ALTO = 200
const porcentajeAlto = computed(() => parseFloat(form.utilidad_porcentaje) > UMBRAL_PORCENTAJE_ALTO)
const multiplicador = computed(() => (1 + parseFloat(form.utilidad_porcentaje) / 100).toFixed(2))

// El proveedor es fijo desde la creación del catálogo (ver 009-catalogos.md); en edición se
// muestra de solo lectura, con el nombre comercial ya conocido para no depender de que
// ProveedorSelect haya terminado de cargar sus opciones.
const proveedorNombreComercial = ref<string | null>(null)

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

onMounted(async () => {
  if (!catalogoId.value) return

  cargando.value = true
  try {
    const catalogo = await catalogos.fetchOne(catalogoId.value)
    form.proveedor_id = catalogo.proveedor_id
    form.nombre = catalogo.nombre
    form.descuento = catalogo.descuento.toString()
    form.utilidad_porcentaje = catalogo.utilidad_porcentaje.toString()
    proveedorNombreComercial.value = catalogo.proveedor_nombre_comercial
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

  const payload: CatalogoPayload = {
    nombre: form.nombre,
    descuento: form.descuento ? parseFloat(form.descuento) : 0,
    utilidad_porcentaje: form.utilidad_porcentaje ? parseFloat(form.utilidad_porcentaje) : 0,
  }

  try {
    if (esEdicion.value && catalogoId.value) {
      await catalogos.update(catalogoId.value, payload)
    } else {
      await catalogos.create({ ...payload, proveedor_id: form.proveedor_id })
    }

    await router.push({ name: 'catalogos' })
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
        {{ esEdicion ? 'Editar catálogo' : 'Nuevo catálogo' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos del catálogo</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label>Proveedor</Label>
              <ProveedorSelect v-if="!esEdicion" v-model="form.proveedor_id" />
              <Input v-else :model-value="proveedorNombreComercial ?? '—'" disabled />
              <p v-if="esEdicion" class="text-muted-foreground text-sm">
                El proveedor no se puede cambiar después de crear el catálogo.
              </p>
              <p v-if="erroresPorCampo.proveedor_id" class="text-destructive text-sm">
                {{ erroresPorCampo.proveedor_id }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="nombre">Nombre</Label>
              <Input id="nombre" v-model="form.nombre" required />
              <p v-if="erroresPorCampo.nombre" class="text-destructive text-sm">
                {{ erroresPorCampo.nombre }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="descuento">Descuento (%)</Label>
              <Input
                id="descuento"
                v-model="form.descuento"
                type="number"
                min="0"
                max="100"
                step="0.01"
              />
              <p v-if="erroresPorCampo.descuento" class="text-destructive text-sm">
                {{ erroresPorCampo.descuento }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="utilidad_porcentaje">Utilidad (%)</Label>
              <Input
                id="utilidad_porcentaje"
                v-model="form.utilidad_porcentaje"
                type="number"
                min="0"
                max="999.99"
                step="0.01"
              />
              <p class="text-muted-foreground text-sm">
                Utilidad por defecto para los artículos de este catálogo.
              </p>
              <p v-if="porcentajeAlto" class="text-sm text-amber-600 dark:text-amber-500">
                Una utilidad del {{ form.utilidad_porcentaje }}% multiplica el costo por
                {{ multiplicador }}. Verifica que sea el valor que querías.
              </p>
              <p v-if="erroresPorCampo.utilidad_porcentaje" class="text-destructive text-sm">
                {{ erroresPorCampo.utilidad_porcentaje }}
              </p>
            </div>
          </CardContent>
        </Card>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'catalogos' })">
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
