<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import { QrCodeIcon } from '@heroicons/vue/24/outline'
import http from '../../lib/http'
import { mensajeDeFalla } from '../../lib/errors'
import { vibrarLectura } from '../../lib/lectorQr'
import { useClientesStore } from '../../stores/clientes'
import type { CamposConstancia } from '../../lib/constanciaFiscal'
import ClienteCombobox, { type ClienteResultado } from '../ClienteCombobox.vue'
import EscanerQr from '../EscanerQr.vue'
import { Alert, AlertDescription } from '../ui/alert'
import { Button } from '../ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '../ui/dialog'

/**
 * El paso de cliente de la factura y de la cotización (ver 029-pwa-mostrador.md).
 *
 * Los dos documentos exigen un cliente del catálogo fiscal, así que al que no está hay que darlo de
 * alta ahí mismo. Se hace **escaneando su constancia**: un CFDI exige RFC, régimen y código postal
 * correctos, y escribir esos tres a mano en un celular, con el cliente enfrente, es la manera más
 * segura de timbrar mal.
 *
 * El camino es el de 016: el navegador lee el QR y manda **solo la dirección** —unos cientos de
 * bytes—, el backend se la pregunta al SAT y devuelve los datos oficiales.
 */

const cliente = defineModel<ClienteResultado | null>({ default: null })

const clientes = useClientesStore()

const escaneando = ref(false)
const procesando = ref(false)
/** Se pinta sobre el video: el escáner sigue trabajando mientras se lee. */
const aviso = ref<string | null>(null)

/** Lo que responde `POST clientes/constancia`; el backend ya validó contra los catálogos del SAT. */
interface RespuestaConstancia {
  fuente: string
  data: CamposConstancia
  cliente_existente: { id: number; razon_social: string } | null
}

const MENSAJES_ERROR: Record<string, string> = {
  QR_NO_OFICIAL: 'Ese código no es el de una Constancia de Situación Fiscal.',
  SAT_NO_DISPONIBLE: 'El SAT no responde. Da de alta al cliente desde la computadora.',
  LIMITE: 'Vas muy rápido. Espera un momento antes de escanear otra constancia.',
}

function abrirEscaner() {
  aviso.value = null
  escaneando.value = true
}

async function onCodigo(codigo: string) {
  if (procesando.value) return

  procesando.value = true
  aviso.value = null

  try {
    const cuerpo = new FormData()
    cuerpo.append('qr_url', codigo)

    const { data } = await http.post<RespuestaConstancia>('/clientes/constancia', cuerpo)

    vibrarLectura()

    // El RFC ya está en el catálogo: se usa esa ficha en vez de crear un duplicado que el backend
    // rechazaría de todos modos por su regla `unique`.
    if (data.cliente_existente) {
      await usarExistente(data.cliente_existente.id)
      return
    }

    await darDeAlta(data.data)
  } catch (err) {
    // Un código que no es una constancia no cierra el escáner: se avisa y se sigue apuntando.
    if (axios.isAxiosError(err) && err.response) {
      const clave =
        err.response.status === 429
          ? 'LIMITE'
          : ((err.response.data as { error?: string } | undefined)?.error ?? '')

      aviso.value = MENSAJES_ERROR[clave] ?? 'No se pudieron obtener los datos de esa constancia.'
      return
    }

    aviso.value = mensajeDeFalla(err)
  } finally {
    procesando.value = false
  }
}

async function usarExistente(id: number) {
  const encontrado = await clientes.fetchOne(id)

  elegir({
    id: encontrado.id,
    razon_social: encontrado.razon_social,
    rfc: encontrado.rfc,
    descuento_permanente: encontrado.descuento_permanente,
  })
}

/**
 * El alta solo procede con los cuatro datos que el CFDI exige. Faltando alguno, la ficha se captura
 * en la computadora: media alta guardada aquí sería un cliente al que después no se le puede
 * timbrar y que nadie sabría que está incompleto.
 */
async function darDeAlta(campos: CamposConstancia) {
  const { rfc, razon_social, regimen_fiscal, codigo_postal_fiscal } = campos

  if (!rfc || !razon_social || !regimen_fiscal || !codigo_postal_fiscal) {
    aviso.value =
      'Esa constancia no trae todos los datos fiscales. Da de alta al cliente en la computadora.'
    return
  }

  try {
    const creado = await clientes.create({
      rfc,
      razon_social,
      regimen_fiscal,
      codigo_postal_fiscal,
      nombre_comercial: null,
      correo_contacto: null,
      telefono: null,
      direccion_comercial: campos.direccion_comercial,
      descuento_permanente: 0,
    })

    elegir({
      id: creado.id,
      razon_social: creado.razon_social,
      rfc: creado.rfc,
      descuento_permanente: creado.descuento_permanente,
    })
  } catch (err) {
    aviso.value = mensajeDeFalla(err)
  }
}

function elegir(elegido: ClienteResultado) {
  cliente.value = elegido
  escaneando.value = false
}
</script>

<template>
  <div class="space-y-4">
    <ClienteCombobox
      :model-value="cliente?.id ?? null"
      @seleccion="(elegido) => (cliente = elegido)"
    />

    <div v-if="cliente" class="border-border bg-background rounded-lg border p-4">
      <p class="text-foreground text-lg font-medium">{{ cliente.razon_social }}</p>
      <p class="text-muted-foreground font-mono text-sm">{{ cliente.rfc }}</p>
      <p v-if="cliente.descuento_permanente > 0" class="text-muted-foreground mt-1 text-sm">
        Descuento permanente de {{ cliente.descuento_permanente }}%, ya aplicado a cada artículo.
      </p>
    </div>

    <Alert v-if="!escaneando && aviso" variant="warning">
      <AlertDescription>{{ aviso }}</AlertDescription>
    </Alert>

    <Button type="button" variant="outline" class="h-14 w-full text-base" @click="abrirEscaner">
      <QrCodeIcon class="size-5" />
      Escanear su constancia
    </Button>

    <p class="text-muted-foreground text-sm">
      Si el cliente no está en el catálogo, apunta al código QR de su Constancia de Situación Fiscal
      y queda dado de alta en el momento.
    </p>

    <Dialog v-model:open="escaneando">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Constancia de Situación Fiscal</DialogTitle>
          <DialogDescription>
            Apunta al código QR de la constancia. Se lee solo, no hay que apretar nada.
          </DialogDescription>
        </DialogHeader>

        <div class="h-72">
          <EscanerQr
            v-if="escaneando"
            class="h-full"
            :aviso="procesando ? 'Consultando al SAT...' : aviso"
            @codigo="onCodigo"
          />
        </div>
      </DialogContent>
    </Dialog>
  </div>
</template>
