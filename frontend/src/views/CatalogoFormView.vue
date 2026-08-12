<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCatalogosStore, type CatalogoPayload, type ImpactoArticulo } from '../stores/catalogos'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
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

/**
 * Aumento porcentual del costo (ver 021-mantenimiento-articulos-catalogos.md). No forma parte del
 * guardado del catálogo: es una acción aparte, con su propia confirmación.
 */
const MAXIMO_AUMENTO = 100

const aumento = ref('')
const articulosCount = ref(0)
const impacto = ref<ImpactoArticulo[] | null>(null)
const cargandoImpacto = ref(false)
const mostrarConfirmarAumento = ref(false)
const aplicandoAumento = ref(false)
const errorAumento = ref<string | null>(null)
const aumentoAplicado = ref<number | null>(null)

const aumentoValido = computed(() => {
  const valor = parseFloat(aumento.value)
  return Number.isFinite(valor) && valor > 0 && valor <= MAXIMO_AUMENTO
})

const catalogoTieneArticulos = computed(() => articulosCount.value > 0)

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
    articulosCount.value = catalogo.articulos_count
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

/**
 * Vista previa con lo que haya en el formulario. Si se mueven el aumento y el descuento a la vez,
 * muestra el resultado de aplicar ambos, que es lo que ocurriría al guardar.
 */
async function verImpacto() {
  if (!catalogoId.value) return

  cargandoImpacto.value = true
  errorAumento.value = null
  aumentoAplicado.value = null
  try {
    impacto.value = await catalogos.impactoPrecios(catalogoId.value, {
      descuento: form.descuento === '' ? null : parseFloat(form.descuento),
      utilidad_porcentaje:
        form.utilidad_porcentaje === '' ? null : parseFloat(form.utilidad_porcentaje),
      aumento_porcentaje: aumentoValido.value ? parseFloat(aumento.value) : null,
    })
  } catch (err) {
    errorAumento.value = extractErrorMessage(err)
  } finally {
    cargandoImpacto.value = false
  }
}

async function confirmarAumento() {
  if (!catalogoId.value || !aumentoValido.value) return

  aplicandoAumento.value = true
  errorAumento.value = null
  try {
    aumentoAplicado.value = await catalogos.aumentarCostos(
      catalogoId.value,
      parseFloat(aumento.value),
    )
    mostrarConfirmarAumento.value = false
    aumento.value = ''
    impacto.value = null
  } catch (err) {
    errorAumento.value = extractErrorMessage(err)
  } finally {
    aplicandoAumento.value = false
  }
}

function pesos(valor: number): string {
  return valor.toFixed(2)
}

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

      <!-- El aumento no forma parte del guardado del catálogo: es una acción aparte, y por eso vive
           fuera del <form> (ver 021-mantenimiento-articulos-catalogos.md). -->
      <Card v-if="esEdicion && !cargando">
        <CardHeader>
          <CardTitle class="text-base">Aumentar costo</CardTitle>
        </CardHeader>
        <CardContent class="min-w-0 space-y-4">
          <p class="text-muted-foreground text-sm">
            Sube el precio de proveedor de todos los artículos del catálogo y recalcula sus precios
            de venta. El descuento, la utilidad y el costo de goma no cambian, así que el margen se
            conserva.
          </p>

          <div class="space-y-1.5">
            <Label for="aumento">Aumento (%)</Label>
            <Input
              id="aumento"
              v-model="aumento"
              type="number"
              min="0.01"
              :max="MAXIMO_AUMENTO"
              step="0.01"
              placeholder="5"
              class="max-w-40"
            />
          </div>

          <div class="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="outline"
              :disabled="cargandoImpacto || !catalogoTieneArticulos"
              @click="verImpacto"
            >
              {{ cargandoImpacto ? 'Calculando...' : 'Ver impacto' }}
            </Button>
            <Button
              type="button"
              :disabled="!aumentoValido || !catalogoTieneArticulos"
              @click="mostrarConfirmarAumento = true"
            >
              Aplicar aumento
            </Button>
          </div>

          <p v-if="!catalogoTieneArticulos" class="text-muted-foreground text-sm">
            Este catálogo no tiene artículos.
          </p>

          <Alert v-if="errorAumento" variant="destructive">
            <AlertDescription>{{ errorAumento }}</AlertDescription>
          </Alert>

          <Alert v-if="aumentoAplicado !== null">
            <AlertDescription> {{ aumentoAplicado }} artículo(s) actualizado(s). </AlertDescription>
          </Alert>

          <!-- La tabla se desplaza dentro de su propio contenedor: un catálogo de cientos de
               artículos no debe desbordar la página (ver 003-design-system-tailwind.md). -->
          <div v-if="impacto" class="min-w-0 space-y-2">
            <p class="text-muted-foreground text-sm">
              Vista previa de {{ impacto.length }} artículo(s). Todavía no se ha guardado nada.
            </p>
            <div class="max-h-96 w-full min-w-0 overflow-auto rounded-md border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Artículo</TableHead>
                    <TableHead class="text-right">Proveedor</TableHead>
                    <TableHead class="text-right">Costo</TableHead>
                    <TableHead class="text-right">Precio de venta</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow v-for="articulo in impacto" :key="articulo.id">
                    <TableCell truncate :title="articulo.nombre">
                      {{ articulo.nombre }}
                      <span class="text-muted-foreground">— {{ articulo.modelo }}</span>
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                      ${{ pesos(articulo.precio_proveedor) }}
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                      ${{ pesos(articulo.costo_total) }}
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                      ${{ pesos(articulo.precio_unitario_sin_iva) }}
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
          </div>
        </CardContent>
      </Card>

      <Dialog
        :open="mostrarConfirmarAumento"
        @update:open="(v) => !v && (mostrarConfirmarAumento = false)"
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Aplicar aumento</DialogTitle>
            <DialogDescription>
              Se aumentará {{ aumento }}% el costo de {{ articulosCount }} artículo(s) de "{{
                form.nombre
              }}". El cambio no se puede deshacer: no queda registro del costo anterior.
            </DialogDescription>
          </DialogHeader>

          <!-- El inventario valúa al costo de hoy (017), así que el dinero invertido sube de golpe
               en la misma proporción. No es un error, pero sin avisarlo lo parece. -->
          <p class="text-muted-foreground text-sm">
            El inventario valúa las existencias al costo de hoy, así que el dinero invertido y el
            beneficio potencial subirán en la misma proporción, aunque esas piezas se hayan comprado
            más baratas.
          </p>

          <Alert v-if="errorAumento" variant="destructive">
            <AlertDescription>{{ errorAumento }}</AlertDescription>
          </Alert>

          <DialogFooter>
            <Button
              variant="outline"
              :disabled="aplicandoAumento"
              @click="mostrarConfirmarAumento = false"
            >
              Cancelar
            </Button>
            <Button :disabled="aplicandoAumento" @click="confirmarAumento">
              {{ aplicandoAumento ? 'Aplicando...' : 'Aplicar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
