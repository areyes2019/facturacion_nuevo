<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  imagenUrl,
  useArticulosStore,
  type Articulo,
  type ArticuloPayload,
} from '../stores/articulos'
import { useCatalogosStore } from '../stores/catalogos'
import { useConfiguracionStore } from '../stores/configuracion'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { calcularCadena, precioConIva, redondeo2 } from '../lib/precioArticulo'
import { etiquetaGoma, SIN_GOMA, TAMANOS_GOMA } from '../lib/tamanoGoma'
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
import CatalogoSelect from '../components/CatalogoSelect.vue'
import ClaveProdServCombobox from '../components/ClaveProdServCombobox.vue'
import ClaveUnidadCombobox from '../components/ClaveUnidadCombobox.vue'
import ObjetoImpuestoSelect from '../components/ObjetoImpuestoSelect.vue'
import AppLayout from '../layouts/AppLayout.vue'

const route = useRoute()
const router = useRouter()
const articulos = useArticulosStore()
const catalogos = useCatalogosStore()
const configuracion = useConfiguracionStore()

const articuloId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => articuloId.value !== null)

const form = reactive({
  catalogo_id: null as number | null,
  nombre: '',
  modelo: '',
  clave_prod_serv: null as string | null,
  clave_unidad: null as string | null,
  objeto_imp: null as string | null,
  precio_proveedor: '' as string,
  utilidad_porcentaje: '' as string,
  // '' = el artículo no lleva goma; es el valor por defecto al crear.
  tamano_goma: '' as string,
})

// Descuento y utilidad del catálogo seleccionado, para mostrar los precios en vivo (ver
// 009-catalogos.md y 011-precio-proveedor-utilidad.md); se consultan cada vez que cambia el
// catálogo porque el combobox solo expone id/nombre, no el descuento ni la utilidad.
const descuentoCatalogo = ref(0)
const utilidadCatalogo = ref(0)
watch(
  () => form.catalogo_id,
  async (catalogoId) => {
    if (!catalogoId) {
      descuentoCatalogo.value = 0
      utilidadCatalogo.value = 0
      return
    }
    try {
      const catalogo = await catalogos.fetchOne(catalogoId)
      descuentoCatalogo.value = catalogo.descuento
      utilidadCatalogo.value = catalogo.utilidad_porcentaje
    } catch {
      descuentoCatalogo.value = 0
      utilidadCatalogo.value = 0
    }
  },
)

// Utilidad efectiva: la del artículo si se capturó, si no la del catálogo.
const utilidadEfectiva = computed(() => {
  const utilidad = parseFloat(form.utilidad_porcentaje)
  if (Number.isFinite(utilidad)) return utilidad
  return utilidadCatalogo.value
})

const precioProveedor = computed(() => {
  const precio = parseFloat(form.precio_proveedor)
  return Number.isFinite(precio) ? precio : 0
})

// Costo vigente de la goma del tamaño elegido, leído de los ajustes globales (ver
// 014-costo-elaboracion-goma.md). Sin tamaño no hay goma, y por lo tanto no hay costo que sumar.
const costoGoma = computed(() => configuracion.costoGoma(form.tamano_goma))
const llevaGoma = computed(() => form.tamano_goma !== '')
const etiquetaDeGoma = computed(() => etiquetaGoma(form.tamano_goma))

// "Sin goma" viaja por el Select con un valor centinela y se traduce aquí, porque Reka UI reserva
// la cadena vacía para limpiar la selección (ver 003-design-system-tailwind.md). El formulario
// sigue guardando '' para la ausencia de goma, que es lo que el resto de la vista y el payload
// esperan.
const tamanoSeleccionado = computed({
  get: () => form.tamano_goma || SIN_GOMA,
  set: (valor: string) => {
    form.tamano_goma = valor === SIN_GOMA ? '' : valor
  },
})

// Cadena completa calculada con el mismo módulo que espeja al backend (ver
// 011-precio-proveedor-utilidad.md). Ninguna vista calcula precios por su cuenta.
const cadena = computed(() =>
  calcularCadena(
    precioProveedor.value,
    descuentoCatalogo.value,
    utilidadEfectiva.value,
    costoGoma.value,
  ),
)

const descuentoMonto = computed(() =>
  redondeo2(precioProveedor.value - cadena.value.costo_con_descuento),
)
const precioFinal = computed(() => precioConIva(cadena.value.precio_unitario_sin_iva))
const ivaMonto = computed(() => redondeo2(precioFinal.value - cadena.value.precio_unitario_sin_iva))

// Aviso no bloqueante: por encima de este porcentaje es mucho más probable un dedazo (1000 en vez
// de 100) que un markup real, pero el markup alto es legítimo y no se impide guardar.
const UMBRAL_PORCENTAJE_ALTO = 200
const porcentajeAlto = computed(() => utilidadEfectiva.value > UMBRAL_PORCENTAJE_ALTO)

function pesos(valor: number): string {
  return valor.toFixed(2)
}

/**
 * Copia del artículo tal como lo devolvió el servidor, para el bloque de imagen (ver
 * 020-imagenes-articulos.md). El formulario trabaja con `form`, que no lleva la imagen porque no
 * viaja en el payload: la foto se guarda contra un artículo que ya existe, por su propio endpoint.
 */
const articuloActual = ref<Articulo | null>(null)
const subiendoImagen = ref(false)
const errorImagen = ref<string | null>(null)
const urlImagen = computed(() => (articuloActual.value ? imagenUrl(articuloActual.value) : null))

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})
// Discrepancia entre el precio que mostró el formulario y el que devolvió el servidor al guardar.
// En operación normal nunca se activa; es la red para un frontend desplegado desactualizado.
const avisoDiscrepancia = ref<string | null>(null)

onMounted(async () => {
  // Los costos de goma alimentan el resumen en vivo y las etiquetas del selector, así que se
  // cargan tanto en alta como en edición.
  if (!configuracion.valores) {
    await configuracion.fetch().catch(() => undefined)
  }

  if (!articuloId.value) return

  cargando.value = true
  try {
    const articulo = await articulos.fetchOne(articuloId.value)
    articuloActual.value = articulo
    form.catalogo_id = articulo.catalogo_id
    form.nombre = articulo.nombre
    form.modelo = articulo.modelo
    form.clave_prod_serv = articulo.clave_prod_serv
    form.clave_unidad = articulo.clave_unidad
    form.objeto_imp = articulo.objeto_imp
    form.precio_proveedor = articulo.precio_proveedor.toString()
    form.utilidad_porcentaje =
      articulo.utilidad_porcentaje !== null ? articulo.utilidad_porcentaje.toString() : ''
    form.tamano_goma = articulo.tamano_goma ?? ''
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

/**
 * Compara la cadena que mostró el formulario contra la que devolvió el servidor. Si no coinciden,
 * el usuario aprobó un precio distinto del que quedó guardado y hay que decírselo en vez de navegar
 * en silencio (ver 011-precio-proveedor-utilidad.md).
 */
function discrepancia(guardado: Articulo): string | null {
  const local = cadena.value

  if (
    guardado.costo_con_descuento === local.costo_con_descuento &&
    guardado.costo_total === local.costo_total &&
    guardado.precio_unitario_sin_iva === local.precio_unitario_sin_iva &&
    guardado.utilidad === local.utilidad
  ) {
    return null
  }

  return (
    `El precio guardado no coincide con el que mostró este formulario. Quedó registrado un costo ` +
    `total de $${pesos(guardado.costo_total)} y un precio de venta de ` +
    `$${pesos(guardado.precio_unitario_sin_iva)}, en lugar de $${pesos(local.costo_total)} ` +
    `y $${pesos(local.precio_unitario_sin_iva)}. Recarga la página y verifica el artículo.`
  )
}

async function onImagenSeleccionada(event: Event) {
  const input = event.target as HTMLInputElement
  const archivo = input.files?.[0]

  if (!archivo || !articuloId.value) return

  subiendoImagen.value = true
  errorImagen.value = null

  try {
    articuloActual.value = await articulos.subirImagen(articuloId.value, archivo)
  } catch (err) {
    errorImagen.value = extractErrorMessage(err)
  } finally {
    subiendoImagen.value = false
    // Sin esto, elegir el mismo archivo dos veces seguidas no dispara el evento y el usuario
    // creería que el reintento no hizo nada.
    input.value = ''
  }
}

async function quitarImagen() {
  if (!articuloId.value) return

  subiendoImagen.value = true
  errorImagen.value = null

  try {
    await articulos.eliminarImagen(articuloId.value)
    if (articuloActual.value) {
      articuloActual.value = {
        ...articuloActual.value,
        tiene_imagen: false,
        imagen_version: null,
      }
    }
  } catch (err) {
    errorImagen.value = extractErrorMessage(err)
  } finally {
    subiendoImagen.value = false
  }
}

async function onSubmit() {
  guardando.value = true
  errorGeneral.value = null
  erroresPorCampo.value = {}
  avisoDiscrepancia.value = null

  const payload: ArticuloPayload = {
    catalogo_id: form.catalogo_id,
    nombre: form.nombre,
    modelo: form.modelo,
    clave_prod_serv: form.clave_prod_serv,
    clave_unidad: form.clave_unidad,
    objeto_imp: form.objeto_imp,
    precio_proveedor: form.precio_proveedor ? parseFloat(form.precio_proveedor) : null,
    utilidad_porcentaje: form.utilidad_porcentaje ? parseFloat(form.utilidad_porcentaje) : null,
    tamano_goma: form.tamano_goma || null,
  }

  try {
    const guardado =
      esEdicion.value && articuloId.value
        ? await articulos.update(articuloId.value, payload)
        : await articulos.create(payload)

    avisoDiscrepancia.value = discrepancia(guardado)

    if (avisoDiscrepancia.value) return

    await router.push({ name: 'articulos' })
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
        {{ esEdicion ? 'Editar artículo' : 'Nuevo artículo' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <Alert v-if="avisoDiscrepancia" variant="destructive">
        <AlertDescription>{{ avisoDiscrepancia }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos del artículo</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label>Catálogo</Label>
              <CatalogoSelect v-model="form.catalogo_id" />
              <p v-if="erroresPorCampo.catalogo_id" class="text-destructive text-sm">
                {{ erroresPorCampo.catalogo_id }}
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
              <Label for="modelo">Modelo</Label>
              <Input id="modelo" v-model="form.modelo" required />
              <p v-if="erroresPorCampo.modelo" class="text-destructive text-sm">
                {{ erroresPorCampo.modelo }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Clave de producto o servicio (SAT)</Label>
              <ClaveProdServCombobox v-model="form.clave_prod_serv" />
              <p v-if="erroresPorCampo.clave_prod_serv" class="text-destructive text-sm">
                {{ erroresPorCampo.clave_prod_serv }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Clave de unidad (SAT)</Label>
              <ClaveUnidadCombobox v-model="form.clave_unidad" />
              <p v-if="erroresPorCampo.clave_unidad" class="text-destructive text-sm">
                {{ erroresPorCampo.clave_unidad }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Objeto de impuesto (SAT)</Label>
              <ObjetoImpuestoSelect v-model="form.objeto_imp" />
              <p v-if="erroresPorCampo.objeto_imp" class="text-destructive text-sm">
                {{ erroresPorCampo.objeto_imp }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="precio_proveedor">Precio de proveedor (MXN)</Label>
              <Input
                id="precio_proveedor"
                v-model="form.precio_proveedor"
                type="number"
                min="0.01"
                step="0.01"
                required
              />
              <p v-if="erroresPorCampo.precio_proveedor" class="text-destructive text-sm">
                {{ erroresPorCampo.precio_proveedor }}
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
                placeholder="Usa la utilidad del catálogo"
              />
              <p class="text-muted-foreground text-sm">
                Si se deja vacío se usa la utilidad del catálogo ({{ utilidadCatalogo }}%).
              </p>
              <p v-if="porcentajeAlto" class="text-sm text-amber-600 dark:text-amber-500">
                Una utilidad del {{ utilidadEfectiva }}% multiplica el costo por
                {{ (1 + utilidadEfectiva / 100).toFixed(2) }}. Verifica que sea el valor que
                querías.
              </p>
              <p v-if="erroresPorCampo.utilidad_porcentaje" class="text-destructive text-sm">
                {{ erroresPorCampo.utilidad_porcentaje }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="tamano_goma">Tamaño de goma</Label>
              <!-- El costo vigente va dentro de cada opción: es donde el dato hace falta, al
                   elegir, no después (ver 014-costo-elaboracion-goma.md). -->
              <Select v-model="tamanoSeleccionado">
                <SelectTrigger id="tamano_goma" class="w-full">
                  <SelectValue placeholder="Sin goma" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem :value="SIN_GOMA">Sin goma</SelectItem>
                  <SelectItem
                    v-for="tamano in TAMANOS_GOMA"
                    :key="tamano.valor"
                    :value="tamano.valor"
                  >
                    {{ tamano.etiqueta }} — ${{ pesos(configuracion.costoGoma(tamano.valor)) }} ({{
                      tamano.medida.toLowerCase()
                    }})
                  </SelectItem>
                </SelectContent>
              </Select>
              <p class="text-muted-foreground text-sm">
                El costo de la goma se suma al costo del aparato. Se edita en Configuración.
              </p>
              <p v-if="erroresPorCampo.tamano_goma" class="text-destructive text-sm">
                {{ erroresPorCampo.tamano_goma }}
              </p>
            </div>

            <!-- Resumen de la cadena de cálculo, siempre visible y en vivo (ver 011). -->
            <div class="bg-muted/40 space-y-1.5 rounded-md border p-4">
              <p class="text-foreground text-sm font-medium">Cadena de cálculo</p>
              <dl class="text-sm">
                <div class="flex justify-between gap-4 py-0.5">
                  <dt class="text-muted-foreground">Precio de lista del proveedor</dt>
                  <dd class="tabular-nums">${{ pesos(precioProveedor) }}</dd>
                </div>
                <div class="flex justify-between gap-4 py-0.5">
                  <dt class="text-muted-foreground">
                    Descuento del catálogo ({{ descuentoCatalogo }}%)
                  </dt>
                  <dd class="tabular-nums">−${{ pesos(descuentoMonto) }}</dd>
                </div>
                <!-- Sin goma el bloque se ve igual que en 011: no se muestran renglones de $0.00
                     que solo agregan ruido a la mayoría de los artículos. -->
                <div class="flex justify-between gap-4 border-t py-0.5 pt-1.5">
                  <dt class="text-muted-foreground">
                    {{ llevaGoma ? 'Costo del aparato' : 'Costo' }}
                  </dt>
                  <dd class="tabular-nums">${{ pesos(cadena.costo_con_descuento) }}</dd>
                </div>
                <template v-if="llevaGoma">
                  <div class="flex justify-between gap-4 py-0.5">
                    <dt class="text-muted-foreground">{{ etiquetaDeGoma }}</dt>
                    <dd class="tabular-nums">+${{ pesos(costoGoma) }}</dd>
                  </div>
                  <div class="flex justify-between gap-4 border-t py-0.5 pt-1.5">
                    <dt class="text-muted-foreground">Costo total</dt>
                    <dd class="tabular-nums">${{ pesos(cadena.costo_total) }}</dd>
                  </div>
                </template>
                <div class="flex justify-between gap-4 py-0.5">
                  <dt class="text-muted-foreground">Utilidad ({{ utilidadEfectiva }}%)</dt>
                  <dd class="tabular-nums">+${{ pesos(cadena.utilidad) }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t py-0.5 pt-1.5">
                  <dt class="text-foreground font-medium">Precio de venta sin IVA</dt>
                  <dd class="tabular-nums font-medium">
                    ${{ pesos(cadena.precio_unitario_sin_iva) }}
                  </dd>
                </div>
                <div class="flex justify-between gap-4 py-0.5">
                  <dt class="text-muted-foreground">IVA (16%)</dt>
                  <dd class="tabular-nums">+${{ pesos(ivaMonto) }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t py-0.5 pt-1.5">
                  <dt class="text-foreground font-medium">Precio de venta con IVA</dt>
                  <dd class="tabular-nums font-medium">${{ pesos(precioFinal) }}</dd>
                </div>
              </dl>
            </div>
          </CardContent>
        </Card>

        <!-- La carga masiva resuelve el volumen; este bloque resuelve la foto suelta que salió
             mal, sin obligar a armar un ZIP para corregir un producto (ver 020). -->
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Imagen</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <p v-if="!esEdicion" class="text-muted-foreground text-sm">
              Guarda el artículo primero: la imagen se asocia a un artículo que ya existe.
            </p>

            <template v-else>
              <div class="flex flex-wrap items-start gap-4">
                <div
                  class="bg-muted flex size-32 shrink-0 items-center justify-center overflow-hidden rounded-md"
                >
                  <img
                    v-if="urlImagen"
                    :src="urlImagen"
                    :alt="form.nombre"
                    class="h-full w-full object-contain"
                  />
                  <span v-else class="text-muted-foreground text-xs">Sin imagen</span>
                </div>

                <div class="min-w-0 flex-1 space-y-2">
                  <Label for="imagen_articulo">
                    {{ urlImagen ? 'Reemplazar imagen' : 'Subir imagen' }}
                  </Label>
                  <input
                    id="imagen_articulo"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    :disabled="subiendoImagen"
                    class="border-input w-full min-w-0 rounded-md border px-3 py-1.5 text-sm"
                    @change="onImagenSeleccionada"
                  />
                  <p class="text-muted-foreground text-sm">
                    JPG, PNG o WEBP, hasta 10 MB. Se guarda reducida a 1200 px de lado.
                  </p>
                  <Button
                    v-if="urlImagen"
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="subiendoImagen"
                    @click="quitarImagen"
                  >
                    Quitar imagen
                  </Button>
                </div>
              </div>

              <Alert v-if="errorImagen" variant="destructive">
                <AlertDescription>{{ errorImagen }}</AlertDescription>
              </Alert>
            </template>
          </CardContent>
        </Card>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'articulos' })">
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
