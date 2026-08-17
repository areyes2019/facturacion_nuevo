<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeftIcon, EnvelopeIcon, ShareIcon } from '@heroicons/vue/24/outline'
import { useFacturasStore, type Factura } from '../../stores/facturas'
import { mensajeDeFalla, mensajeDeFallaDeDescarga } from '../../lib/errors'
import type { ArchivoCompartible } from '../../lib/compartir'
import AppLayout from '../../layouts/AppLayout.vue'
import { Alert, AlertDescription } from '../../components/ui/alert'
import { Badge } from '../../components/ui/badge'
import { Button } from '../../components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../../components/ui/dialog'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'

/**
 * El detalle de una factura en el mostrador (ver 031-mostrador-consulta.md).
 *
 * Consultar y reenviar, nada más: **no se cancela, no se edita, no se elimina y no se emiten
 * complementos de pago**. Cancelar un CFDI exige un motivo, queda registrado ante la autoridad y no
 * se deshace; no es algo que se aprieta con el pulgar en el mostrador.
 */

const ESTADOS: Record<
  string,
  { etiqueta: string; variant: 'secondary' | 'warning' | 'success' | 'destructive' }
> = {
  borrador: { etiqueta: 'Borrador', variant: 'secondary' },
  pendiente: { etiqueta: 'Pendiente', variant: 'warning' },
  timbrada: { etiqueta: 'Timbrada', variant: 'success' },
  cancelada: { etiqueta: 'Cancelada', variant: 'destructive' },
}

const route = useRoute()
const router = useRouter()
const facturas = useFacturasStore()

const factura = ref<Factura | null>(null)
const cargando = ref(true)
const error = ref<string | null>(null)

const timbrando = ref(false)
const errorTimbrado = ref<string | null>(null)

const correo = ref('')
const enviando = ref(false)
const dialogoCorreo = ref(false)
const compartiendo = ref(false)
const avisoEnvio = ref<string | null>(null)

/** El PDF ya bajado, listo para el menú de compartir (ver 029, supuesto 78). */
const archivo = ref<ArchivoCompartible | null>(null)

const estado = computed(() =>
  factura.value
    ? (ESTADOS[factura.value.estado] ?? { etiqueta: factura.value.estado, variant: 'secondary' })
    : null,
)

const timbrada = computed(() => factura.value?.estado === 'timbrada')

async function cargar() {
  cargando.value = true
  error.value = null

  try {
    factura.value = await facturas.fetchOne(Number(route.params.id))
    correo.value = factura.value.cliente_correo ?? ''

    if (timbrada.value) void prepararEnvio()
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    cargando.value = false
  }
}

void cargar()

/**
 * El PDF se baja **al entrar a la pantalla**, no al apretar el botón: el menú de compartir del
 * aparato solo se abre mientras el gesto del usuario sigue vivo, y una descarga de por medio lo
 * agota (ver 029, supuesto 78).
 */
async function prepararEnvio() {
  if (factura.value === null) return

  compartiendo.value = true
  avisoEnvio.value = null

  try {
    archivo.value = await facturas.archivoParaWhatsapp(factura.value)
  } catch (err) {
    avisoEnvio.value = await mensajeDeFallaDeDescarga(err)
  } finally {
    compartiendo.value = false
  }
}

/** Por WhatsApp va **solo el PDF**: el navegador no admite `.xml` en su menú (ver 029, supuesto 81). */
async function compartirPorWhatsapp() {
  if (factura.value === null || archivo.value === null) return

  avisoEnvio.value = null

  try {
    const resultado = await facturas.compartirPorWhatsapp(factura.value, archivo.value)

    if (resultado === 'descargado') {
      avisoEnvio.value = 'PDF descargado: adjúntalo en la ventana de WhatsApp que acaba de abrirse.'
    } else if (resultado === 'compartido') {
      avisoEnvio.value = 'Factura compartida. El XML va por correo.'
    }
  } catch (err) {
    avisoEnvio.value = mensajeDeFalla(err)
  }
}

async function enviarPorCorreo() {
  if (factura.value === null || correo.value.trim() === '') return

  enviando.value = true
  avisoEnvio.value = null

  try {
    await facturas.enviarCorreo(factura.value.id, [correo.value.trim()])
    dialogoCorreo.value = false
    avisoEnvio.value = 'Factura enviada por correo.'
  } catch (err) {
    avisoEnvio.value = mensajeDeFalla(err)
  } finally {
    enviando.value = false
  }
}

/**
 * Reintenta el timbrado de una factura que quedó guardada sin timbrar. Los tres datos fiscales no se
 * pueden cambiar aquí: si el timbrado falló por uno de ellos, la factura se corrige en la
 * computadora.
 */
async function reintentarTimbrado() {
  if (factura.value === null) return

  timbrando.value = true
  errorTimbrado.value = null

  try {
    factura.value = await facturas.timbrar(factura.value.id)

    if (factura.value.estado === 'timbrada') {
      void prepararEnvio()
      return
    }

    errorTimbrado.value = factura.value.error_timbrado ?? 'No se pudo timbrar la factura.'
  } catch (err) {
    errorTimbrado.value = mensajeDeFalla(err)
  } finally {
    timbrando.value = false
  }
}

function fecha(iso: string): string {
  return new Date(iso).toLocaleDateString()
}
</script>

<template>
  <AppLayout mostrador barra>
    <div class="mx-auto max-w-md space-y-4">
      <Button variant="ghost" size="sm" class="-ml-2" @click="router.back()">
        <ArrowLeftIcon class="size-4" />
        Facturas
      </Button>

      <p v-if="cargando" class="text-muted-foreground py-8 text-center">Cargando...</p>

      <Alert v-else-if="error" variant="destructive">
        <AlertDescription class="space-y-2">
          <p>{{ error }}</p>
          <Button type="button" size="sm" variant="outline" @click="cargar">Reintentar</Button>
        </AlertDescription>
      </Alert>

      <template v-else-if="factura">
        <div class="space-y-1">
          <div class="flex items-center gap-2">
            <h1 class="font-heading text-foreground font-mono text-xl font-semibold">
              #{{ factura.folio }}
            </h1>
            <Badge v-if="estado" :variant="estado.variant">{{ estado.etiqueta }}</Badge>
          </div>
          <p class="text-foreground text-lg font-medium break-words">
            {{ factura.cliente_razon_social }}
          </p>
          <p class="text-muted-foreground font-mono">{{ factura.cliente_rfc }}</p>
          <p class="text-muted-foreground text-sm">{{ fecha(factura.created_at) }}</p>
        </div>

        <div v-if="factura.uuid_fiscal">
          <p class="text-muted-foreground text-xs uppercase">Folio fiscal</p>
          <p class="font-mono text-sm break-all">{{ factura.uuid_fiscal }}</p>
        </div>

        <ul class="border-border divide-border divide-y rounded-lg border">
          <li
            v-for="linea in factura.lineas"
            :key="linea.id"
            class="flex items-start justify-between gap-3 p-3"
          >
            <div class="min-w-0 flex-1">
              <p class="text-foreground font-medium break-words">{{ linea.descripcion }}</p>
              <p class="text-muted-foreground text-sm">
                {{ linea.cantidad }} × ${{ linea.precio_unitario.toFixed(2) }}
              </p>
            </div>
            <span class="shrink-0 font-semibold tabular-nums">
              ${{ linea.importe.toFixed(2) }}
            </span>
          </li>
        </ul>

        <div class="border-border flex items-baseline justify-between border-t pt-3">
          <span class="text-muted-foreground">Total</span>
          <span class="text-3xl font-semibold tabular-nums">${{ factura.total.toFixed(2) }}</span>
        </div>

        <!-- Cancelada: se dice con su motivo y no se ofrece ningún envío. -->
        <Alert v-if="factura.estado === 'cancelada'" variant="destructive">
          <AlertDescription>
            Factura cancelada{{
              factura.motivo_cancelacion ? ` (motivo ${factura.motivo_cancelacion})` : ''
            }}.
          </AlertDescription>
        </Alert>

        <!-- Pendiente: el motivo del fallo a la vista y el botón que reintenta. -->
        <template v-else-if="!timbrada">
          <Alert variant="destructive">
            <AlertDescription>
              {{
                errorTimbrado ??
                factura.error_timbrado ??
                'Esta factura quedó guardada sin timbrar.'
              }}
            </AlertDescription>
          </Alert>

          <Button class="h-14 w-full text-base" :disabled="timbrando" @click="reintentarTimbrado">
            {{ timbrando ? 'Timbrando...' : 'Reintentar timbrado' }}
          </Button>

          <p class="text-muted-foreground text-sm">
            Los datos fiscales se corrigen desde la computadora.
          </p>
        </template>

        <template v-else>
          <Alert v-if="avisoEnvio">
            <AlertDescription>{{ avisoEnvio }}</AlertDescription>
          </Alert>

          <Button
            class="h-14 w-full text-base"
            :disabled="compartiendo"
            @click="compartirPorWhatsapp"
          >
            <ShareIcon class="size-5" />
            {{ compartiendo ? 'Preparando...' : 'Enviar por WhatsApp' }}
          </Button>

          <Button variant="outline" class="h-14 w-full text-base" @click="dialogoCorreo = true">
            <EnvelopeIcon class="size-5" />
            Enviar por correo
          </Button>

          <!-- Sin esta línea, el usuario cree que le mandó el CFDI completo al contador del
               cliente. El navegador del celular no admite archivos XML en su menú de compartir
               (ver 029, "El XML no cabe en el menú del aparato"). -->
          <p class="text-muted-foreground text-center text-sm">
            Por WhatsApp va el PDF; el XML se manda por correo.
          </p>
        </template>
      </template>
    </div>

    <Dialog v-model:open="dialogoCorreo">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Enviar por correo</DialogTitle>
          <DialogDescription>
            Sale del servidor con el PDF y el XML adjuntos. Viene el correo del cliente; puedes
            cambiarlo.
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-1.5">
          <Label for="correo-factura-detalle">Correo</Label>
          <Input id="correo-factura-detalle" v-model="correo" type="email" class="h-12 text-base" />
        </div>

        <DialogFooter>
          <Button variant="outline" @click="dialogoCorreo = false">Cancelar</Button>
          <Button :disabled="enviando || correo.trim() === ''" @click="enviarPorCorreo">
            {{ enviando ? 'Enviando...' : 'Enviar' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
