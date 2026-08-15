<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { CheckCircleIcon } from '@heroicons/vue/24/outline'
import http, { ensureCsrfCookie } from '../lib/http'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import RegimenFiscalSelect from '../components/RegimenFiscalSelect.vue'
import UsoCfdiCombobox from '../components/UsoCfdiCombobox.vue'

/**
 * Portal público de autofacturación (ver 027-venta-mostrador-ticket.md).
 *
 * La ve un cliente que no tiene cuenta en el sistema, así que va sin `AppLayout`, sin menú y sin
 * nada que suponga sesión iniciada. Lo único que la autoriza es el token de la URL.
 */
interface DatosPedido {
  numero_ticket: string
  fecha: string
  total: number
  correo_sugerido: string | null
  no_disponible: string | null
  vence_el: string
}

const route = useRoute()
const token = String(route.params.token)

const datos = ref<DatosPedido | null>(null)
const cargando = ref(true)
const errorCarga = ref<string | null>(null)

const form = reactive({
  rfc: '',
  razon_social: '',
  regimen_fiscal: null as string | null,
  codigo_postal_fiscal: '',
  uso_cfdi: null as string | null,
  correo: '',
})

const enviando = ref(false)
const errorEnvio = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})
const timbrada = ref<{ folio: number; uuid_fiscal: string | null; correo: string } | null>(null)

onMounted(async () => {
  try {
    const { data } = await http.get(`/autofactura/${token}`)
    datos.value = data
    form.correo = data.correo_sugerido ?? ''
  } catch (err) {
    errorCarga.value =
      extractErrorMessage(err) === 'Ocurrió un error inesperado.'
        ? 'Este enlace no es válido.'
        : extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

async function onSubmit() {
  enviando.value = true
  errorEnvio.value = null
  erroresPorCampo.value = {}

  try {
    // El POST viaja por el mismo grupo `api` que exige CSRF a la SPA. Quien abre esta página llega
    // de cero, sin cookie, así que hay que pedirla antes de enviar.
    await ensureCsrfCookie()

    const { data } = await http.post(`/autofactura/${token}`, {
      rfc: form.rfc.trim().toUpperCase(),
      razon_social: form.razon_social.trim(),
      regimen_fiscal: form.regimen_fiscal,
      codigo_postal_fiscal: form.codigo_postal_fiscal.trim(),
      uso_cfdi: form.uso_cfdi,
      correo: form.correo.trim(),
    })

    timbrada.value = { folio: data.folio, uuid_fiscal: data.uuid_fiscal, correo: data.correo }
  } catch (err) {
    erroresPorCampo.value = extractFieldErrors(err)
    errorEnvio.value = extractErrorMessage(err)
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <main class="bg-muted flex min-h-screen justify-center p-4">
    <div class="w-full max-w-lg space-y-4 py-8">
      <p v-if="cargando" class="text-muted-foreground text-center">Cargando...</p>

      <Card v-else-if="errorCarga">
        <CardHeader>
          <CardTitle>Enlace no válido</CardTitle>
          <CardDescription>{{ errorCarga }}</CardDescription>
        </CardHeader>
      </Card>

      <Card v-else-if="timbrada">
        <CardHeader class="items-center text-center">
          <CheckCircleIcon class="text-primary size-12" />
          <CardTitle>Tu factura está lista</CardTitle>
          <CardDescription>
            La enviamos a <strong>{{ timbrada.correo }}</strong
            >. Revisa también la carpeta de correo no deseado.
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-1 text-center text-sm">
          <p class="text-muted-foreground">Folio {{ timbrada.folio }}</p>
          <p v-if="timbrada.uuid_fiscal" class="text-muted-foreground font-mono text-xs break-all">
            {{ timbrada.uuid_fiscal }}
          </p>
        </CardContent>
      </Card>

      <template v-else-if="datos">
        <Card>
          <CardHeader>
            <CardTitle>Factura tu compra</CardTitle>
            <CardDescription>
              Ticket {{ datos.numero_ticket }} · {{ new Date(datos.fecha).toLocaleDateString() }} ·
              Total ${{ datos.total.toFixed(2) }}
            </CardDescription>
          </CardHeader>
        </Card>

        <Alert v-if="datos.no_disponible" variant="destructive">
          <AlertDescription>{{ datos.no_disponible }}</AlertDescription>
        </Alert>

        <Card v-else>
          <CardHeader>
            <CardTitle class="text-base">Tus datos fiscales</CardTitle>
            <CardDescription>
              Escríbelos tal como aparecen en tu Constancia de Situación Fiscal.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form class="space-y-4" @submit.prevent="onSubmit">
              <div class="space-y-1.5">
                <Label for="rfc">RFC</Label>
                <Input id="rfc" v-model="form.rfc" autocapitalize="characters" autocomplete="off" />
                <p v-if="erroresPorCampo.rfc" class="text-destructive text-sm">
                  {{ erroresPorCampo.rfc }}
                </p>
              </div>

              <div class="space-y-1.5">
                <Label for="razon_social">Razón social</Label>
                <Input id="razon_social" v-model="form.razon_social" autocomplete="off" />
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
                <Label for="cp">Código postal</Label>
                <Input
                  id="cp"
                  v-model="form.codigo_postal_fiscal"
                  inputmode="numeric"
                  maxlength="5"
                  autocomplete="off"
                />
                <p v-if="erroresPorCampo.codigo_postal_fiscal" class="text-destructive text-sm">
                  {{ erroresPorCampo.codigo_postal_fiscal }}
                </p>
              </div>

              <div class="space-y-1.5">
                <Label>Uso de CFDI</Label>
                <UsoCfdiCombobox v-model="form.uso_cfdi" />
                <p v-if="erroresPorCampo.uso_cfdi" class="text-destructive text-sm">
                  {{ erroresPorCampo.uso_cfdi }}
                </p>
              </div>

              <div class="space-y-1.5">
                <Label for="correo">Correo para enviarte la factura</Label>
                <Input id="correo" v-model="form.correo" type="email" autocomplete="email" />
                <p v-if="erroresPorCampo.correo" class="text-destructive text-sm">
                  {{ erroresPorCampo.correo }}
                </p>
              </div>

              <Alert v-if="errorEnvio" variant="destructive">
                <AlertDescription>{{ errorEnvio }}</AlertDescription>
              </Alert>

              <Button type="submit" class="w-full" :disabled="enviando">
                {{ enviando ? 'Generando tu factura...' : 'Generar factura' }}
              </Button>
            </form>
          </CardContent>
        </Card>
      </template>
    </div>
  </main>
</template>
