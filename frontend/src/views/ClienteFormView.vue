<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useClientesStore, type ClientePayload } from '../stores/clientes'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import RegimenFiscalSelect from '../components/RegimenFiscalSelect.vue'
import CodigoPostalCombobox from '../components/CodigoPostalCombobox.vue'
import ConstanciaFiscalDropzone from '../components/ConstanciaFiscalDropzone.vue'
import type { ResultadoConstancia } from '../lib/constanciaFiscal'
import AppLayout from '../layouts/AppLayout.vue'

const route = useRoute()
const router = useRouter()
const clientes = useClientesStore()

const clienteId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => clienteId.value !== null)

const form = reactive({
  rfc: '',
  razon_social: '',
  regimen_fiscal: null as string | null,
  codigo_postal_fiscal: null as string | null,
  nombre_comercial: '',
  correo_contacto: '',
  telefono: '',
  direccion_comercial: '',
  descuento_permanente: 0 as number | string,
  es_distribuidor: false,
})

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

// Precarga desde la Constancia de Situación Fiscal (ver
// specs/016-constancia-situacion-fiscal-qr.md).
const constancia = ref<ResultadoConstancia | null>(null)
const ocrRevisado = ref(false)
const duplicadoIgnorado = ref(false)

/**
 * Los datos oficiales del SAT no llevan aviso; los otros dos caminos sí.
 *
 * Ninguno de los dos mensajes afirma que el SAT esté caído, porque no siempre es el motivo:
 * también se llega aquí cuando el código QR no se pudo leer y nunca hubo a quién preguntarle.
 * Decirle al usuario que el SAT falló cuando el problema fue el documento lo manda a esperar a que
 * se componga algo que nunca estuvo roto.
 */
const avisoConstancia = computed(() => {
  if (!constancia.value || constancia.value.fuente === 'SAT_QR_DIRECT') return null

  return constancia.value.fuente === 'PDF_TEXTO'
    ? 'Estos datos se tomaron de la constancia que subiste y no se confirmaron con el SAT; verifica que sea reciente y que el domicilio siga vigente.'
    : 'La constancia es una imagen y no se pudo confirmar con el SAT, así que los datos se reconocieron automáticamente y pueden tener errores. Revisa carácter por carácter el RFC y el código postal antes de guardar.'
})

/**
 * Medio segundo de fricción puesto exactamente donde el error es caro e invisible: un RFC con un
 * carácter mal no se nota al leerlo y termina en un CFDI rechazado.
 */
const requiereRevisionOcr = computed(
  () => constancia.value?.fuente === 'OCR_LOCAL' && !ocrRevisado.value,
)

const clienteDuplicado = computed(() => {
  const existente = constancia.value?.clienteExistente

  if (!existente || duplicadoIgnorado.value) return null

  // En edición, que el RFC sea el del cliente que ya se está editando no es un duplicado.
  return existente.id === clienteId.value ? null : existente
})

function onDatosExtraidos(resultado: ResultadoConstancia) {
  constancia.value = resultado
  ocrRevisado.value = false
  duplicadoIgnorado.value = false

  if (resultado.clienteExistente && resultado.clienteExistente.id !== clienteId.value) {
    // Se espera la decisión del usuario antes de tocar el formulario.
    return
  }

  precargar(resultado)
}

/**
 * Los campos que la constancia trae reemplazan lo que hubiera escrito. Nombre comercial, correo,
 * teléfono y descuento no se tocan nunca: la constancia no los trae y borrarlos destruiría trabajo
 * del usuario.
 */
function precargar(resultado: ResultadoConstancia) {
  const { campos } = resultado

  if (campos.rfc) form.rfc = campos.rfc
  if (campos.razon_social) form.razon_social = campos.razon_social
  if (campos.regimen_fiscal) form.regimen_fiscal = campos.regimen_fiscal
  if (campos.codigo_postal_fiscal) form.codigo_postal_fiscal = campos.codigo_postal_fiscal
  if (campos.direccion_comercial) form.direccion_comercial = campos.direccion_comercial
}

function precargarDeTodosModos() {
  duplicadoIgnorado.value = true

  if (constancia.value) precargar(constancia.value)
}

onMounted(async () => {
  if (!clienteId.value) return

  cargando.value = true
  try {
    const cliente = await clientes.fetchOne(clienteId.value)
    form.rfc = cliente.rfc
    form.razon_social = cliente.razon_social
    form.regimen_fiscal = cliente.regimen_fiscal
    form.codigo_postal_fiscal = cliente.codigo_postal_fiscal
    form.nombre_comercial = cliente.nombre_comercial ?? ''
    form.correo_contacto = cliente.correo_contacto ?? ''
    form.telefono = cliente.telefono ?? ''
    form.direccion_comercial = cliente.direccion_comercial ?? ''
    form.descuento_permanente = cliente.descuento_permanente
    form.es_distribuidor = cliente.es_distribuidor
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

  const payload: ClientePayload = {
    rfc: form.rfc,
    razon_social: form.razon_social,
    regimen_fiscal: form.regimen_fiscal ?? '',
    codigo_postal_fiscal: form.codigo_postal_fiscal ?? '',
    nombre_comercial: form.nombre_comercial || null,
    correo_contacto: form.correo_contacto || null,
    telefono: form.telefono || null,
    direccion_comercial: form.direccion_comercial || null,
    // Un campo en blanco equivale a 0%: el backend aplica el mismo criterio (ver
    // 015-descuento-permanente-cliente.md).
    descuento_permanente: Number(form.descuento_permanente) || 0,
    es_distribuidor: form.es_distribuidor,
  }

  try {
    if (esEdicion.value && clienteId.value) {
      await clientes.update(clienteId.value, payload)
    } else {
      await clientes.create(payload)
    }
    await router.push({ name: 'clientes' })
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
        {{ esEdicion ? 'Editar cliente' : 'Nuevo cliente' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <ConstanciaFiscalDropzone v-if="!cargando" @datos-extraidos="onDatosExtraidos" />

      <Alert v-if="clienteDuplicado" variant="warning">
        <AlertDescription class="space-y-3">
          <p>
            Ya tienes registrado a <strong>{{ clienteDuplicado.razon_social }}</strong> con este
            RFC.
          </p>
          <div class="flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              @click="router.push({ name: 'clientes-editar', params: { id: clienteDuplicado.id } })"
            >
              Abrir su ficha
            </Button>
            <Button type="button" size="sm" variant="outline" @click="precargarDeTodosModos">
              Precargar de todos modos
            </Button>
          </div>
        </AlertDescription>
      </Alert>

      <Alert v-if="avisoConstancia" variant="warning">
        <AlertDescription class="space-y-2">
          <p>{{ avisoConstancia }}</p>

          <ul v-if="constancia?.advertencias.length" class="list-disc space-y-1 pl-4">
            <li v-for="advertencia in constancia.advertencias" :key="advertencia">
              {{ advertencia }}
            </li>
          </ul>

          <label
            v-if="constancia?.fuente === 'OCR_LOCAL'"
            class="flex items-center gap-2 font-medium"
          >
            <input v-model="ocrRevisado" type="checkbox" class="accent-primary size-4" />
            Revisé estos datos y son correctos
          </label>
        </AlertDescription>
      </Alert>

      <Alert
        v-else-if="constancia?.advertencias.length"
        variant="warning"
        data-testid="advertencias-sat"
      >
        <AlertDescription>
          <ul class="list-disc space-y-1 pl-4">
            <li v-for="advertencia in constancia.advertencias" :key="advertencia">
              {{ advertencia }}
            </li>
          </ul>
        </AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos fiscales</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label for="rfc">RFC</Label>
              <Input id="rfc" v-model="form.rfc" maxlength="13" required />
              <p v-if="erroresPorCampo.rfc" class="text-destructive text-sm">
                {{ erroresPorCampo.rfc }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="razon_social">Razón social</Label>
              <Input id="razon_social" v-model="form.razon_social" required />
              <p v-if="erroresPorCampo.razon_social" class="text-destructive text-sm">
                {{ erroresPorCampo.razon_social }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Régimen fiscal</Label>
              <RegimenFiscalSelect v-model="form.regimen_fiscal" />
              <p v-if="erroresPorCampo.regimen_fiscal" class="text-destructive text-sm">
                {{ erroresPorCampo.regimen_fiscal }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label>Código postal fiscal</Label>
              <CodigoPostalCombobox v-model="form.codigo_postal_fiscal" />
              <p v-if="erroresPorCampo.codigo_postal_fiscal" class="text-destructive text-sm">
                {{ erroresPorCampo.codigo_postal_fiscal }}
              </p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle class="text-base">Datos comerciales (opcionales)</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="space-y-1.5">
              <Label for="nombre_comercial">Nombre comercial</Label>
              <Input id="nombre_comercial" v-model="form.nombre_comercial" />
            </div>

            <div class="space-y-1.5">
              <Label for="correo_contacto">Correo de contacto</Label>
              <Input id="correo_contacto" v-model="form.correo_contacto" type="email" />
              <p v-if="erroresPorCampo.correo_contacto" class="text-destructive text-sm">
                {{ erroresPorCampo.correo_contacto }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="telefono">Teléfono</Label>
              <Input id="telefono" v-model="form.telefono" />
            </div>

            <div class="space-y-1.5">
              <Label for="direccion_comercial">Dirección comercial</Label>
              <Input id="direccion_comercial" v-model="form.direccion_comercial" />
            </div>

            <div class="space-y-1.5">
              <Label for="descuento_permanente">Descuento permanente (%)</Label>
              <Input
                id="descuento_permanente"
                v-model="form.descuento_permanente"
                type="number"
                min="0"
                max="50"
                step="0.01"
              />
              <p class="text-muted-foreground text-sm">
                Se aplicará automáticamente a cada línea de las cotizaciones de este cliente. Máximo
                50%.
              </p>
              <p v-if="erroresPorCampo.descuento_permanente" class="text-destructive text-sm">
                {{ erroresPorCampo.descuento_permanente }}
              </p>
            </div>

            <div class="space-y-1.5">
              <label class="flex items-center gap-2 font-medium">
                <input
                  v-model="form.es_distribuidor"
                  type="checkbox"
                  class="accent-primary size-4"
                />
                Es distribuidor
              </label>
              <p class="text-muted-foreground text-sm">
                Sus cotizaciones y facturas usarán el precio distribuidor de cada artículo en vez
                del precio de lista.
              </p>
            </div>
          </CardContent>
        </Card>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'clientes' })">
            Cancelar
          </Button>
          <Button type="submit" :disabled="guardando || requiereRevisionOcr">
            {{ guardando ? 'Guardando...' : 'Guardar' }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
