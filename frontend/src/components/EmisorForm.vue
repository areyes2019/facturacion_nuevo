<script setup lang="ts">
import { onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useEmisorStore, type TipoLogo } from '../stores/emisor'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from './ui/button'
import { Card, CardContent, CardHeader, CardTitle } from './ui/card'
import { Input } from './ui/input'
import { Label } from './ui/label'
import { Alert, AlertDescription } from './ui/alert'
import RegimenFiscalSelect from './RegimenFiscalSelect.vue'

/**
 * Datos fiscales del emisor y sus dos logos (ver specs/019-formato-pdf-documentos.md).
 *
 * Sección hermana de "Costos de elaboración" dentro de Configuración, con su propio botón de
 * guardar: son dos formularios distintos y mezclarlos obligaría a confirmar el recálculo de
 * precios de los artículos para cambiar un teléfono.
 */
const emisor = useEmisorStore()

const form = reactive({
  nombre: '',
  rfc: '',
  regimen_fiscal: null as string | null,
  domicilio: '',
  correo: '',
  telefono: '',
})

const cargando = ref(true)
const guardando = ref(false)
const guardado = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

/** Vista previa de cada logo, como URL local del navegador. */
const previos = reactive<Record<TipoLogo, string | null>>({ principal: null, marca: null })
const subiendo = ref<TipoLogo | null>(null)

const inputPrincipal = ref<HTMLInputElement | null>(null)
const inputMarca = ref<HTMLInputElement | null>(null)

onMounted(async () => {
  try {
    const datos = await emisor.fetch()
    form.nombre = datos.nombre ?? ''
    form.rfc = datos.rfc ?? ''
    form.regimen_fiscal = datos.regimen_fiscal
    form.domicilio = datos.domicilio ?? ''
    form.correo = datos.correo ?? ''
    form.telefono = datos.telefono ?? ''

    await Promise.all([refrescarPrevio('principal'), refrescarPrevio('marca')])
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

// La confirmación deja de ser cierta en cuanto se toca un campo: sostenerla mientras hay cambios
// sin guardar es peor que no darla.
watch(form, () => {
  guardado.value = false
})

// Las vistas previas son objetos vivos del navegador: si no se sueltan, quedan en memoria hasta
// recargar la página.
onUnmounted(() => {
  liberar('principal')
  liberar('marca')
})

function liberar(tipo: TipoLogo) {
  if (previos[tipo]) {
    URL.revokeObjectURL(previos[tipo]!)
    previos[tipo] = null
  }
}

async function refrescarPrevio(tipo: TipoLogo) {
  liberar(tipo)

  const hay = tipo === 'marca' ? emisor.datos?.tiene_logo_marca : emisor.datos?.tiene_logo

  if (!hay) {
    return
  }

  try {
    previos[tipo] = await emisor.logoPrevio(tipo)
  } catch {
    // Un logo que no se puede previsualizar no impide capturar los datos fiscales.
  }
}

async function onArchivo(tipo: TipoLogo, evento: Event) {
  const input = evento.target as HTMLInputElement
  const archivo = input.files?.[0]

  if (!archivo) {
    return
  }

  subiendo.value = tipo
  errorGeneral.value = null

  try {
    await emisor.subirLogo(tipo, archivo)
    await refrescarPrevio(tipo)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    subiendo.value = null
    // Permite volver a elegir el mismo archivo después de un error.
    input.value = ''
  }
}

async function quitarLogo(tipo: TipoLogo) {
  try {
    await emisor.eliminarLogo(tipo)
    liberar(tipo)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  }
}

async function onSubmit() {
  guardando.value = true
  guardado.value = false
  errorGeneral.value = null
  erroresPorCampo.value = {}

  try {
    await emisor.update({
      nombre: form.nombre,
      rfc: form.rfc,
      regimen_fiscal: form.regimen_fiscal,
      domicilio: form.domicilio || null,
      correo: form.correo || null,
      telefono: form.telefono || null,
    })
    guardado.value = true
  } catch (err) {
    erroresPorCampo.value = extractFieldErrors(err)
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="text-base">Datos del emisor</CardTitle>
    </CardHeader>
    <CardContent class="space-y-4">
      <p class="text-muted-foreground text-sm">
        Encabezan tus cotizaciones, facturas y órdenes de compra. Son los mismos para todo el
        sistema.
      </p>

      <Alert v-if="emisor.incompleto" variant="destructive">
        <AlertDescription>
          Tus documentos se están imprimiendo sin datos fiscales del emisor.
        </AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-4" @submit.prevent="onSubmit">
        <div class="space-y-1.5">
          <Label for="emisor-nombre">Nombre o razón social</Label>
          <Input id="emisor-nombre" v-model="form.nombre" />
          <p v-if="erroresPorCampo.nombre" class="text-destructive text-sm">
            {{ erroresPorCampo.nombre }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label for="emisor-rfc">RFC</Label>
          <Input id="emisor-rfc" v-model="form.rfc" class="max-w-56 uppercase" />
          <p v-if="erroresPorCampo.rfc" class="text-destructive text-sm">
            {{ erroresPorCampo.rfc }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label for="emisor-regimen">Régimen fiscal</Label>
          <RegimenFiscalSelect v-model="form.regimen_fiscal" />
          <p v-if="erroresPorCampo.regimen_fiscal" class="text-destructive text-sm">
            {{ erroresPorCampo.regimen_fiscal }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label for="emisor-domicilio">Domicilio</Label>
          <Input id="emisor-domicilio" v-model="form.domicilio" />
          <!-- Una sola línea, tal como se imprime en el documento. -->
          <p class="text-muted-foreground text-sm">
            Se imprime en un renglón, por ejemplo: 38024, Celaya, Guanajuato, MEX
          </p>
          <p v-if="erroresPorCampo.domicilio" class="text-destructive text-sm">
            {{ erroresPorCampo.domicilio }}
          </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="space-y-1.5">
            <Label for="emisor-correo">Correo</Label>
            <Input id="emisor-correo" v-model="form.correo" type="email" />
            <p v-if="erroresPorCampo.correo" class="text-destructive text-sm">
              {{ erroresPorCampo.correo }}
            </p>
          </div>

          <div class="space-y-1.5">
            <Label for="emisor-telefono">Teléfono</Label>
            <Input id="emisor-telefono" v-model="form.telefono" />
            <p v-if="erroresPorCampo.telefono" class="text-destructive text-sm">
              {{ erroresPorCampo.telefono }}
            </p>
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="space-y-1.5">
            <Label>Logo</Label>
            <div class="flex items-center gap-3">
              <img
                v-if="previos.principal"
                :src="previos.principal"
                alt="Logo del emisor"
                class="bg-muted h-14 max-w-32 rounded border object-contain p-1"
              />
              <span v-else class="text-muted-foreground text-sm">Sin logo</span>
            </div>
            <input
              ref="inputPrincipal"
              type="file"
              accept="image/png,image/jpeg"
              class="hidden"
              @change="onArchivo('principal', $event)"
            />
            <div class="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="subiendo === 'principal'"
                @click="inputPrincipal?.click()"
              >
                {{ subiendo === 'principal' ? 'Subiendo...' : 'Elegir imagen' }}
              </Button>
              <Button
                v-if="emisor.datos?.tiene_logo"
                type="button"
                variant="ghost"
                size="sm"
                @click="quitarLogo('principal')"
              >
                Quitar
              </Button>
            </div>
          </div>

          <div class="space-y-1.5">
            <Label>Logo de marca</Label>
            <div class="flex items-center gap-3">
              <img
                v-if="previos.marca"
                :src="previos.marca"
                alt="Logo de marca"
                class="bg-muted h-14 max-w-32 rounded border object-contain p-1"
              />
              <span v-else class="text-muted-foreground text-sm">Sin logo</span>
            </div>
            <input
              ref="inputMarca"
              type="file"
              accept="image/png,image/jpeg"
              class="hidden"
              @change="onArchivo('marca', $event)"
            />
            <div class="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="subiendo === 'marca'"
                @click="inputMarca?.click()"
              >
                {{ subiendo === 'marca' ? 'Subiendo...' : 'Elegir imagen' }}
              </Button>
              <Button
                v-if="emisor.datos?.tiene_logo_marca"
                type="button"
                variant="ghost"
                size="sm"
                @click="quitarLogo('marca')"
              >
                Quitar
              </Button>
            </div>
          </div>
        </div>

        <p class="text-muted-foreground text-sm">
          PNG o JPG, hasta 2 MB. El logo se incrusta en cada documento, así que uno muy pesado
          engorda todos los correos que lo llevan adjunto.
        </p>

        <!-- La respuesta a "Guardar" vive junto al botón, no en la cabecera de la tarjeta: este
             formulario es largo y un mensaje arriba queda fuera de la pantalla justo cuando el
             usuario acaba de pulsar, así que desde donde mira no pasa nada. -->
        <Alert v-if="errorGeneral" variant="destructive">
          <AlertDescription>{{ errorGeneral }}</AlertDescription>
        </Alert>

        <Alert v-if="guardado">
          <AlertDescription>Los datos del emisor se guardaron.</AlertDescription>
        </Alert>

        <div class="flex justify-end">
          <Button type="submit" :disabled="guardando">
            {{ guardando ? 'Guardando...' : 'Guardar emisor' }}
          </Button>
        </div>
      </form>
    </CardContent>
  </Card>
</template>
