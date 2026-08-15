<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  ArrowDownIcon,
  ArrowUpIcon,
  Bars3Icon,
  PencilIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline'
import {
  urlLogoBanco,
  useDatosBancariosStore,
  type DatoBancario,
  type DatoBancarioPayload,
} from '../stores/datosBancarios'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from './ui/button'
import { Card, CardContent, CardHeader, CardTitle } from './ui/card'
import { Input } from './ui/input'
import { Label } from './ui/label'
import { Alert, AlertDescription } from './ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from './ui/dialog'

/**
 * Cuentas bancarias del negocio que se imprimen en la cotización
 * (ver specs/026-datos-bancarios-cotizacion.md).
 *
 * Sección hermana de "Datos del emisor" y "Costos de elaboración", con su propio guardado: agregar
 * un banco no puede arrastrar el recálculo de precios de los costos de goma.
 */
const datosBancarios = useDatosBancariosStore()

const cargando = ref(true)
const errorGeneral = ref<string | null>(null)

/** Diálogo de alta/edición. `editando` es el banco en curso, o `null` si es un alta. */
const dialogoAbierto = ref(false)
const editando = ref<DatoBancario | null>(null)
const guardando = ref(false)
const erroresPorCampo = ref<Record<string, string>>({})

const form = reactive({
  nombre_banco: '',
  beneficiario: '',
  numero_cuenta: '',
  tarjeta: '',
  clabe: '',
  visible_en_cotizaciones: true,
})

/** Confirmación de borrado: el banco que se va a eliminar, o `null`. */
const porEliminar = ref<DatoBancario | null>(null)

/**
 * Archivo de logo elegido dentro del diálogo, todavía sin subir, y su vista previa local.
 *
 * El logo se manda **después** de guardar el banco, en una segunda petición, porque se sube contra
 * un banco que ya existe. El alta y la edición siguen el mismo camino: un solo recorrido es más
 * fácil de seguir que dos, y en el alta no hay alternativa.
 */
const logoPendiente = ref<File | null>(null)
const previoLogo = ref<string | null>(null)
const inputLogo = ref<HTMLInputElement | null>(null)
const quitarLogoAlGuardar = ref(false)

/** Id del banco que se está arrastrando. */
const arrastrando = ref<number | null>(null)

const titulo = computed(() => (editando.value ? 'Editar banco' : 'Agregar banco'))

onMounted(async () => {
  try {
    await datosBancarios.fetch()
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

function abrirAlta() {
  editando.value = null
  Object.assign(form, {
    nombre_banco: '',
    beneficiario: '',
    numero_cuenta: '',
    tarjeta: '',
    clabe: '',
    visible_en_cotizaciones: true,
  })
  limpiarLogoPendiente()
  erroresPorCampo.value = {}
  dialogoAbierto.value = true
}

function abrirEdicion(banco: DatoBancario) {
  editando.value = banco
  Object.assign(form, {
    nombre_banco: banco.nombre_banco,
    beneficiario: banco.beneficiario ?? '',
    numero_cuenta: banco.numero_cuenta ?? '',
    tarjeta: banco.tarjeta ?? '',
    clabe: banco.clabe ?? '',
    visible_en_cotizaciones: banco.visible_en_cotizaciones,
  })
  limpiarLogoPendiente()
  erroresPorCampo.value = {}
  dialogoAbierto.value = true
}

/** Las vistas previas son objetos vivos del navegador: sin soltarlas quedan en memoria. */
function limpiarLogoPendiente() {
  if (previoLogo.value) {
    URL.revokeObjectURL(previoLogo.value)
  }
  previoLogo.value = null
  logoPendiente.value = null
  quitarLogoAlGuardar.value = false
}

function onArchivoLogo(evento: Event) {
  const input = evento.target as HTMLInputElement
  const archivo = input.files?.[0]

  if (!archivo) {
    return
  }

  limpiarLogoPendiente()
  logoPendiente.value = archivo
  previoLogo.value = URL.createObjectURL(archivo)

  // Permite volver a elegir el mismo archivo después de un error.
  input.value = ''
}

/** Quita el logo. Se aplica al guardar, para que "Cancelar" siga deshaciendo todo el diálogo. */
function quitarLogo() {
  limpiarLogoPendiente()
  quitarLogoAlGuardar.value = true
}

/** Lo que se muestra en el diálogo: la imagen recién elegida, o la que el banco ya tenía. */
const previoActual = computed(() => {
  if (previoLogo.value) {
    return previoLogo.value
  }

  if (quitarLogoAlGuardar.value || !editando.value?.tiene_logo) {
    return null
  }

  return urlLogoBanco(editando.value)
})

function payload(): DatoBancarioPayload {
  return {
    nombre_banco: form.nombre_banco,
    beneficiario: form.beneficiario || null,
    numero_cuenta: form.numero_cuenta || null,
    tarjeta: form.tarjeta || null,
    clabe: form.clabe || null,
    visible_en_cotizaciones: form.visible_en_cotizaciones,
  }
}

async function guardar() {
  guardando.value = true
  erroresPorCampo.value = {}
  errorGeneral.value = null

  let banco: DatoBancario

  try {
    banco = editando.value
      ? await datosBancarios.actualizar(editando.value.id, payload())
      : await datosBancarios.crear(payload())
  } catch (err) {
    erroresPorCampo.value = extractFieldErrors(err)
    guardando.value = false
    return
  }

  // El logo va en una segunda petición, contra el banco que acaba de existir. Si falla, el banco
  // ya quedó guardado y se avisa: es preferible a subir un archivo contra algo que no existe.
  try {
    if (logoPendiente.value) {
      await datosBancarios.subirLogo(banco.id, logoPendiente.value)
    } else if (quitarLogoAlGuardar.value) {
      await datosBancarios.eliminarLogo(banco.id)
    }

    dialogoAbierto.value = false
    limpiarLogoPendiente()
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
    dialogoAbierto.value = false
    limpiarLogoPendiente()
  } finally {
    guardando.value = false
  }
}

/**
 * El interruptor se guarda solo, sin abrir el diálogo: es un cambio de una sola pulsación y pedir
 * "editar → cambiar → guardar" para apagar un banco sobraría.
 */
async function alternarVisible(banco: DatoBancario) {
  errorGeneral.value = null

  try {
    await datosBancarios.actualizar(banco.id, {
      nombre_banco: banco.nombre_banco,
      beneficiario: banco.beneficiario,
      numero_cuenta: banco.numero_cuenta,
      tarjeta: banco.tarjeta,
      clabe: banco.clabe,
      visible_en_cotizaciones: !banco.visible_en_cotizaciones,
    })
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  }
}

async function eliminar() {
  if (!porEliminar.value) {
    return
  }

  try {
    await datosBancarios.eliminar(porEliminar.value.id)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    porEliminar.value = null
  }
}

/** Manda la lista completa reordenada; el backend rechaza un reordenamiento parcial. */
async function mover(desde: number, hasta: number) {
  if (hasta < 0 || hasta >= datosBancarios.bancos.length || desde === hasta) {
    return
  }

  const ids = datosBancarios.bancos.map((banco) => banco.id)
  const [movido] = ids.splice(desde, 1)
  ids.splice(hasta, 0, movido)

  try {
    await datosBancarios.reordenar(ids)
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  }
}

function onDrop(indiceDestino: number) {
  const indiceOrigen = datosBancarios.bancos.findIndex((banco) => banco.id === arrastrando.value)
  arrastrando.value = null

  if (indiceOrigen !== -1) {
    mover(indiceOrigen, indiceDestino)
  }
}
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle class="text-base">Datos bancarios</CardTitle>
    </CardHeader>
    <CardContent class="space-y-4">
      <p class="text-muted-foreground text-sm">
        Se imprimen en tus cotizaciones, junto al folio, para que el cliente sepa a dónde pagarte.
        No aparecen en las facturas ni en las órdenes de compra, y no tienen nada que ver con las
        cuentas de Tesorería.
      </p>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <p v-if="cargando" class="text-muted-foreground text-sm">Cargando...</p>

      <p v-else-if="datosBancarios.bancos.length === 0" class="text-muted-foreground text-sm">
        Todavía no capturas ninguna cuenta. Las cotizaciones se imprimen sin el bloque de pago.
      </p>

      <ul v-else class="space-y-2">
        <!-- El arrastre nativo no existe en pantallas táctiles: sin las flechas, reordenar desde
             un celular sería imposible. Los dos caminos llaman a `mover`. -->
        <li
          v-for="(banco, indice) in datosBancarios.bancos"
          :key="banco.id"
          draggable="true"
          class="bg-card flex items-start gap-3 rounded-md border p-3"
          :class="{ 'opacity-50': arrastrando === banco.id }"
          @dragstart="arrastrando = banco.id"
          @dragend="arrastrando = null"
          @dragover.prevent
          @drop.prevent="onDrop(indice)"
        >
          <Bars3Icon class="text-muted-foreground mt-0.5 size-4 shrink-0 cursor-grab" />

          <div class="min-w-0 flex-1" :class="{ 'opacity-60': !banco.visible_en_cotizaciones }">
            <p class="flex items-center gap-2 font-medium">
              <img
                v-if="banco.tiene_logo"
                :src="urlLogoBanco(banco)"
                :alt="banco.nombre_banco"
                class="h-5 max-w-10 object-contain"
              />
              {{ banco.nombre_banco }}
            </p>
            <p v-if="banco.beneficiario" class="text-muted-foreground text-sm">
              {{ banco.beneficiario }}
            </p>
            <p v-if="banco.numero_cuenta" class="text-muted-foreground text-sm break-all">
              Cta: {{ banco.numero_cuenta }}
            </p>
            <p v-if="banco.tarjeta" class="text-muted-foreground text-sm break-all">
              Tarjeta: {{ banco.tarjeta }}
            </p>
            <p v-if="banco.clabe" class="text-muted-foreground text-sm break-all">
              CLABE: {{ banco.clabe }}
            </p>
            <p v-if="!banco.visible_en_cotizaciones" class="text-muted-foreground mt-1 text-xs">
              No se muestra en cotizaciones
            </p>
          </div>

          <div class="flex shrink-0 flex-col items-end gap-1">
            <div class="flex gap-1">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="indice === 0"
                aria-label="Subir"
                @click="mover(indice, indice - 1)"
              >
                <ArrowUpIcon class="size-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                :disabled="indice === datosBancarios.bancos.length - 1"
                aria-label="Bajar"
                @click="mover(indice, indice + 1)"
              >
                <ArrowDownIcon class="size-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Editar"
                @click="abrirEdicion(banco)"
              >
                <PencilIcon class="size-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Eliminar"
                @click="porEliminar = banco"
              >
                <TrashIcon class="text-destructive size-4" />
              </Button>
            </div>

            <label class="text-muted-foreground flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                class="accent-primary size-4"
                :checked="banco.visible_en_cotizaciones"
                @change="alternarVisible(banco)"
              />
              Mostrar en cotizaciones
            </label>
          </div>
        </li>
      </ul>

      <div class="flex justify-end">
        <Button type="button" variant="outline" @click="abrirAlta">Agregar banco</Button>
      </div>
    </CardContent>
  </Card>

  <Dialog v-model:open="dialogoAbierto">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ titulo }}</DialogTitle>
        <DialogDescription>
          El nombre del banco es obligatorio. Captura al menos uno de los tres números.
        </DialogDescription>
      </DialogHeader>

      <form class="space-y-3" @submit.prevent="guardar">
        <div class="space-y-1.5">
          <Label for="banco-nombre">Nombre del banco</Label>
          <Input id="banco-nombre" v-model="form.nombre_banco" />
          <p v-if="erroresPorCampo.nombre_banco" class="text-destructive text-sm">
            {{ erroresPorCampo.nombre_banco }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label for="banco-beneficiario">Beneficiario</Label>
          <Input id="banco-beneficiario" v-model="form.beneficiario" />
          <p class="text-muted-foreground text-sm">A nombre de quién está la cuenta. Opcional.</p>
          <p v-if="erroresPorCampo.beneficiario" class="text-destructive text-sm">
            {{ erroresPorCampo.beneficiario }}
          </p>
        </div>

        <!-- `text` con `inputmode="numeric"`, nunca `type="number"`: un número de 18 dígitos
             pierde precisión, salen flechitas de incremento sobre algo que no es una cantidad, y
             el cero inicial de una cuenta desaparece. -->
        <div class="space-y-1.5">
          <Label for="banco-cuenta">Número de cuenta</Label>
          <Input id="banco-cuenta" v-model="form.numero_cuenta" inputmode="numeric" />
          <p v-if="erroresPorCampo.numero_cuenta" class="text-destructive text-sm">
            {{ erroresPorCampo.numero_cuenta }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label for="banco-tarjeta">Tarjeta</Label>
          <Input id="banco-tarjeta" v-model="form.tarjeta" inputmode="numeric" />
          <p v-if="erroresPorCampo.tarjeta" class="text-destructive text-sm">
            {{ erroresPorCampo.tarjeta }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label for="banco-clabe">Clave interbancaria (CLABE)</Label>
          <Input id="banco-clabe" v-model="form.clabe" inputmode="numeric" />
          <p class="text-muted-foreground text-sm">18 dígitos. Puedes pegarla con espacios.</p>
          <p v-if="erroresPorCampo.clabe" class="text-destructive text-sm">
            {{ erroresPorCampo.clabe }}
          </p>
        </div>

        <div class="space-y-1.5">
          <Label>Logo del banco</Label>
          <div class="flex items-center gap-3">
            <img
              v-if="previoActual"
              :src="previoActual"
              alt="Logo del banco"
              class="bg-muted h-10 max-w-20 rounded border object-contain p-1"
            />
            <span v-else class="text-muted-foreground text-sm">Sin logo</span>
          </div>
          <input
            ref="inputLogo"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            class="hidden"
            @change="onArchivoLogo"
          />
          <div class="flex gap-2">
            <Button type="button" variant="outline" size="sm" @click="inputLogo?.click()">
              Elegir imagen
            </Button>
            <Button v-if="previoActual" type="button" variant="ghost" size="sm" @click="quitarLogo">
              Quitar
            </Button>
          </div>
          <p class="text-muted-foreground text-sm">
            PNG, JPG o WEBP, hasta 2 MB. Se guarda reducido a un icono pequeño y se imprime junto al
            nombre del banco.
          </p>
        </div>

        <label class="flex items-center gap-2 text-sm">
          <input
            v-model="form.visible_en_cotizaciones"
            type="checkbox"
            class="accent-primary size-4"
          />
          Mostrar en cotizaciones
        </label>

        <!-- "Captura al menos un número" no es culpa de ninguno de los tres campos en particular,
             así que se muestra al pie y no colgado del primero. -->
        <Alert v-if="erroresPorCampo.datos_bancarios" variant="destructive">
          <AlertDescription>{{ erroresPorCampo.datos_bancarios }}</AlertDescription>
        </Alert>

        <DialogFooter>
          <Button type="button" variant="outline" @click="dialogoAbierto = false">Cancelar</Button>
          <Button type="submit" :disabled="guardando">
            {{ guardando ? 'Guardando...' : 'Guardar' }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>

  <Dialog :open="porEliminar !== null" @update:open="porEliminar = null">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Eliminar {{ porEliminar?.nombre_banco }}</DialogTitle>
        <DialogDescription>
          Dejará de imprimirse en las cotizaciones que hagas a partir de ahora. Las que ya creaste
          conservan los datos con los que salieron. Si solo quieres dejar de usarlo por un tiempo,
          apaga "Mostrar en cotizaciones" en vez de eliminarlo.
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="porEliminar = null">Cancelar</Button>
        <Button variant="destructive" @click="eliminar">Eliminar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
