<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from 'axios'
import http from '../lib/http'
import {
  extraerCamposDeTexto,
  leerQr,
  reconocerTexto,
  renderizarPrimeraPagina,
  type CamposConstancia,
  type RegimenDisponible,
  type ResultadoConstancia,
} from '../lib/constanciaFiscal'
import { Alert, AlertDescription } from './ui/alert'
import { Button } from './ui/button'

/**
 * Zona de carga de la Constancia de Situación Fiscal (ver
 * specs/016-constancia-situacion-fiscal-qr.md).
 *
 * El orden importa: el navegador lee el QR con el lector nativo del dispositivo y, si lo logra,
 * manda al backend **solo la dirección** —unos cientos de bytes— sin subir ni la imagen ni el PDF.
 * Los archivos suben únicamente cuando el SAT está caído o el QR no se pudo leer, que es cuando la
 * Estrategia B los necesita.
 */

const emit = defineEmits<{ (e: 'datos-extraidos', resultado: ResultadoConstancia): void }>()

type Estado = 'reposo' | 'preparando' | 'buscando-qr' | 'consultando' | 'reconociendo'

const TIPOS_ACEPTADOS = ['application/pdf', 'image/jpeg', 'image/png']
const BYTES_MAXIMOS = 10 * 1024 * 1024

const MENSAJES_ESTADO: Record<Exclude<Estado, 'reposo'>, string> = {
  preparando: 'Leyendo el documento…',
  'buscando-qr': 'Buscando el código QR…',
  consultando: 'Consultando al SAT…',
  reconociendo: 'Reconociendo texto…',
}

const MENSAJES_ERROR: Record<string, string> = {
  QR_NO_OFICIAL:
    'El código QR de este documento no apunta al SAT. Verifica que sea una Constancia de Situación Fiscal oficial.',
  QR_NO_LEGIBLE:
    'No se pudo leer el código QR. Intenta con el PDF original, o con una foto más de frente y con buena luz.',
  PDF_SIN_TEXTO: 'No se pudieron extraer los datos de este documento. Captúralos manualmente.',
  SIN_DATOS: 'No se pudieron extraer los datos de este documento. Captúralos manualmente.',
  LIMITE: 'Vas muy rápido. Espera un momento antes de subir otra constancia.',
}

interface RespuestaConstancia {
  fuente: 'SAT_QR_DIRECT' | 'PDF_TEXTO'
  confianza: string
  advertencias: string[]
  data: CamposConstancia & { regimenes_disponibles: RegimenDisponible[] }
  cliente_existente: { id: number; razon_social: string } | null
}

interface FalloConstancia {
  error: string
  estrategia_b?: boolean
  puede_ocr?: boolean
}

const estado = ref<Estado>('reposo')
const error = ref<string | null>(null)
const arrastrando = ref(false)
const entrada = ref<HTMLInputElement | null>(null)

const ocupado = computed(() => estado.value !== 'reposo')
const mensajeEstado = computed(() =>
  estado.value === 'reposo' ? null : MENSAJES_ESTADO[estado.value],
)

function onSoltar(evento: DragEvent) {
  arrastrando.value = false
  const archivo = evento.dataTransfer?.files?.[0]

  if (archivo) void procesar(archivo)
}

function onSeleccionar(evento: Event) {
  const archivo = (evento.target as HTMLInputElement).files?.[0]

  if (archivo) void procesar(archivo)
  // Permite volver a elegir el mismo archivo después de un error.
  if (entrada.value) entrada.value.value = ''
}

/**
 * Llama al endpoint. Devuelve la respuesta cuando salió bien, o el cuerpo del fallo para que el
 * flujo decida si aún le queda algo por intentar.
 */
async function analizar(partes: {
  qrUrl?: string | null
  imagen?: Blob | null
  pdf?: File | null
}): Promise<RespuestaConstancia | FalloConstancia> {
  const cuerpo = new FormData()

  if (partes.qrUrl) cuerpo.append('qr_url', partes.qrUrl)
  if (partes.imagen) cuerpo.append('imagen', partes.imagen, 'constancia.png')
  if (partes.pdf) cuerpo.append('pdf', partes.pdf)

  try {
    const { data } = await http.post<RespuestaConstancia>('/clientes/constancia', cuerpo)

    return data
  } catch (err) {
    if (axios.isAxiosError(err) && err.response) {
      if (err.response.status === 429) return { error: 'LIMITE' }

      return (err.response.data as FalloConstancia) ?? { error: 'SIN_DATOS' }
    }

    return { error: 'SIN_DATOS' }
  }
}

function esExito(
  respuesta: RespuestaConstancia | FalloConstancia,
): respuesta is RespuestaConstancia {
  return 'fuente' in respuesta
}

function emitirDelBackend(respuesta: RespuestaConstancia) {
  const { regimenes_disponibles: regimenes, ...campos } = respuesta.data

  emit('datos-extraidos', {
    fuente: respuesta.fuente,
    campos,
    regimenesDisponibles: regimenes ?? [],
    advertencias: respuesta.advertencias ?? [],
    clienteExistente: respuesta.cliente_existente,
  })
}

async function procesar(archivo: File) {
  error.value = null

  if (!TIPOS_ACEPTADOS.includes(archivo.type)) {
    error.value = 'Solo se aceptan archivos PDF, JPG o PNG.'

    return
  }

  if (archivo.size > BYTES_MAXIMOS) {
    error.value = 'El archivo pesa más de 10 MB. Sube una versión más ligera.'

    return
  }

  try {
    await ejecutar(archivo)
  } catch {
    error.value = 'No se pudo leer el documento. Captura los datos manualmente.'
  } finally {
    estado.value = 'reposo'
  }
}

async function ejecutar(archivo: File) {
  const esPdf = archivo.type === 'application/pdf'

  estado.value = 'preparando'
  const imagen: Blob = esPdf ? await renderizarPrimeraPagina(archivo) : archivo

  estado.value = 'buscando-qr'
  const qrUrl = await leerQr(imagen)

  estado.value = 'consultando'

  // Primera llamada: si el navegador leyó el QR, con la dirección basta y no se sube nada.
  if (qrUrl) {
    const respuesta = await analizar({ qrUrl })

    if (esExito(respuesta)) {
      emitirDelBackend(respuesta)

      return
    }

    if (respuesta.error !== 'SAT_NO_DISPONIBLE') {
      error.value = MENSAJES_ERROR[respuesta.error] ?? MENSAJES_ERROR.SIN_DATOS

      return
    }
  }

  // Segunda llamada: el SAT no está, o el QR no se pudo leer. Ahora sí suben los archivos, que es
  // lo que la Estrategia B necesita para trabajar.
  const respuesta = await analizar({ qrUrl, imagen, pdf: esPdf ? archivo : null })

  if (esExito(respuesta)) {
    emitirDelBackend(respuesta)

    return
  }

  if (!respuesta.puede_ocr) {
    error.value = MENSAJES_ERROR[respuesta.error] ?? MENSAJES_ERROR.SIN_DATOS

    return
  }

  // Último recurso: reconocer los caracteres aquí mismo. Los datos salen marcados porque el
  // reconocimiento puede equivocarse justo donde no se nota, como un RFC.
  estado.value = 'reconociendo'
  const campos = extraerCamposDeTexto(await reconocerTexto(imagen))

  if (!campos.rfc) {
    error.value = MENSAJES_ERROR.SIN_DATOS

    return
  }

  emit('datos-extraidos', {
    fuente: 'OCR_LOCAL',
    campos,
    regimenesDisponibles: [],
    advertencias: [],
    // Sin backend de por medio no se pudo verificar si el RFC ya existe; la regla `unique` del
    // guardado sigue impidiendo el duplicado real.
    clienteExistente: null,
  })
}
</script>

<template>
  <div class="space-y-2">
    <div
      class="border-input hover:bg-accent/40 rounded-lg border border-dashed p-6 text-center transition-colors"
      :class="{ 'border-primary bg-accent/60': arrastrando, 'opacity-60': ocupado }"
      @dragover.prevent="arrastrando = true"
      @dragleave.prevent="arrastrando = false"
      @drop.prevent="onSoltar"
    >
      <p v-if="ocupado" class="text-muted-foreground text-sm">{{ mensajeEstado }}</p>

      <template v-else>
        <p class="text-foreground text-sm font-medium">
          Arrastra aquí la Constancia de Situación Fiscal
        </p>
        <p class="text-muted-foreground mt-1 text-xs">PDF, JPG o PNG · máximo 10 MB</p>
        <Button type="button" variant="outline" size="sm" class="mt-3" @click="entrada?.click()">
          Elegir archivo
        </Button>
      </template>

      <input
        ref="entrada"
        type="file"
        class="hidden"
        accept="application/pdf,image/jpeg,image/png"
        @change="onSeleccionar"
      />
    </div>

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>
  </div>
</template>
