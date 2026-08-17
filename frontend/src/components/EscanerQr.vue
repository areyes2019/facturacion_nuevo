<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { BoltIcon, CameraIcon } from '@heroicons/vue/24/outline'
import { crearLectorQr, leerQr, type LectorQr } from '../lib/lectorQr'
import { Button } from './ui/button'

/**
 * Cámara trasera a pantalla completa que **captura sola** (ver 029-pwa-mostrador.md).
 *
 * No hay botón de disparo: se leen los cuadros de video hasta reconocer un código. Apuntar y
 * esperar es lo único que se puede hacer con un paquete en la otra mano.
 *
 * El componente no decide si un código sirve: emite lo que lee y quien lo use valida. Lo que sí
 * hace es no repetirse mientras la misma etiqueta siga frente a la cámara.
 *
 * Sus tres ayudas —linterna, pantalla despierta y respaldo por foto— son opcionales por
 * naturaleza: cada una se ofrece solo si el aparato la soporta y su ausencia nunca rompe el
 * escaneo.
 */

/** Aviso corto sobre el video, que escribe quien usa el escáner al descartar un código ajeno. */
defineProps<{ aviso?: string | null }>()

const emit = defineEmits<{ codigo: [valor: string] }>()

/** Cinco lecturas por segundo: suficiente para que apuntar se sienta instantáneo, sin freír la CPU. */
const INTERVALO_LECTURA_MS = 200

/**
 * La linterna (`torch`) no está en el estándar de restricciones de medios, así que ni las
 * definiciones del DOM la conocen: la pista se describe a mano para poder preguntarle por ella.
 */
interface PistaConLinterna {
  getCapabilities?(): { torch?: boolean }
  applyConstraints(restricciones: { advanced: { torch: boolean }[] }): Promise<void>
}

/** `WakeLock` no está en las definiciones del DOM de todos los navegadores; se describe a mano. */
interface CandadoPantalla {
  release(): Promise<void>
}

interface NavegadorConWakeLock {
  wakeLock?: { request(tipo: 'screen'): Promise<CandadoPantalla> }
}

const video = ref<HTMLVideoElement | null>(null)
const entradaFoto = ref<HTMLInputElement | null>(null)

const error = ref<string | null>(null)
const linternaDisponible = ref(false)
const linternaEncendida = ref(false)
const leyendoFoto = ref(false)

/**
 * El respaldo por foto entra cuando la cámara en vivo no es una opción: el navegador no la deja
 * abrir, o no trae detector de códigos. En vez de un botón muerto, la pantalla ofrece tomarle una
 * foto a la etiqueta.
 */
const camaraEnVivo = ref(false)

/**
 * El navegador no trae detector de códigos. La foto sigue a la vista porque el detector puede
 * aparecer en una versión posterior del mismo navegador, pero se avisa por adelantado de que ahí no
 * hay nada que leer: es más honesto que dejar que la foto falle sin explicación.
 */
const sinDetector = ref(false)

let lector: LectorQr | null = null
let flujo: MediaStream | null = null
let temporizador: ReturnType<typeof setInterval> | undefined
let candado: CandadoPantalla | null = null
let ultimoCodigo: string | null = null

onMounted(async () => {
  lector = crearLectorQr()

  if (lector === null) {
    sinDetector.value = true
    error.value =
      'Este navegador no trae lector de códigos. Abre la etiqueta con la app de cámara del teléfono.'
    return
  }

  await abrirCamara()
  await pedirPantallaDespierta()
  document.addEventListener('visibilitychange', alCambiarVisibilidad)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', alCambiarVisibilidad)
  clearInterval(temporizador)
  flujo?.getTracks().forEach((pista) => pista.stop())
  flujo = null
  void soltarPantallaDespierta()
})

async function abrirCamara() {
  try {
    flujo = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' } },
    })
  } catch {
    error.value = 'No se pudo abrir la cámara. Revisa el permiso del navegador.'
    return
  }

  if (video.value === null) return

  video.value.srcObject = flujo
  await video.value.play().catch(() => undefined)

  camaraEnVivo.value = true
  detectarLinterna()
  temporizador = setInterval(() => void leerCuadro(), INTERVALO_LECTURA_MS)
}

async function leerCuadro() {
  const elemento = video.value

  if (lector === null || elemento === null || elemento.readyState < elemento.HAVE_CURRENT_DATA) {
    return
  }

  const codigo = await lector.leer(elemento)

  if (codigo === null) {
    // Perder de vista la etiqueta rearma el escáner: volver a apuntarle a la misma vuelve a valer.
    ultimoCodigo = null
    return
  }

  if (codigo === ultimoCodigo) return

  ultimoCodigo = codigo
  emit('codigo', codigo)
}

function pistaDeVideo(): PistaConLinterna | undefined {
  return flujo?.getVideoTracks()[0] as unknown as PistaConLinterna | undefined
}

function detectarLinterna() {
  linternaDisponible.value = pistaDeVideo()?.getCapabilities?.().torch === true
}

/** Para las etiquetas bajo la sombra del mostrador, sin salir de la aplicación. */
async function alternarLinterna() {
  const pista = pistaDeVideo()

  if (pista === undefined) return

  const encendida = !linternaEncendida.value

  try {
    // `torch` viaja dentro de `advanced`, que es donde los navegadores aceptan las capacidades
    // que no están en el estándar de restricciones.
    await pista.applyConstraints({ advanced: [{ torch: encendida }] })
    linternaEncendida.value = encendida
  } catch {
    linternaDisponible.value = false
  }
}

/**
 * Sin esto hay que desbloquear el celular entre etiqueta y etiqueta. Se suelta al salir de la
 * pantalla y se vuelve a pedir al regresar, que es lo que exige esa API cuando el aparato se va a
 * segundo plano.
 */
async function pedirPantallaDespierta() {
  const api = (navigator as Navigator & NavegadorConWakeLock).wakeLock

  if (api === undefined || candado !== null) return

  try {
    candado = await api.request('screen')
  } catch {
    candado = null
  }
}

async function soltarPantallaDespierta() {
  const actual = candado
  candado = null

  await actual?.release().catch(() => undefined)
}

function alCambiarVisibilidad() {
  if (document.visibilityState === 'visible') {
    void pedirPantallaDespierta()
  } else {
    void soltarPantallaDespierta()
  }
}

/**
 * Respaldo por foto: se le toma una foto a la etiqueta y se lee el código de la imagen. Con
 * Android no hace falta; entra como seguro para el aparato cuyo navegador no abre la cámara en vivo.
 */
async function onFoto(evento: Event) {
  const archivo = (evento.target as HTMLInputElement).files?.[0]

  // Permite volver a elegir la misma foto después de un intento fallido.
  if (entradaFoto.value) entradaFoto.value.value = ''
  if (!archivo) return

  leyendoFoto.value = true
  error.value = null

  try {
    const codigo = await leerQr(archivo)

    if (codigo === null) {
      error.value = sinDetector.value
        ? 'Este navegador no trae lector de códigos, así que tampoco puede leer la foto. Abre la etiqueta con la app de cámara del teléfono.'
        : 'No se pudo leer ningún código en esa foto. Intenta de frente y con buena luz.'
      return
    }

    emit('codigo', codigo)
  } finally {
    leyendoFoto.value = false
  }
}
</script>

<template>
  <div class="bg-foreground relative w-full overflow-hidden rounded-lg">
    <video
      v-show="camaraEnVivo"
      ref="video"
      class="h-full w-full object-cover"
      autoplay
      muted
      playsinline
    ></video>

    <!-- Recuadro guía al centro: dice dónde poner la etiqueta sin tapar lo que la cámara ve. -->
    <div
      v-if="camaraEnVivo"
      class="pointer-events-none absolute inset-0 flex items-center justify-center"
    >
      <div class="size-56 max-w-[70%] rounded-2xl border-4 border-white/80"></div>
    </div>

    <Button
      v-if="camaraEnVivo && linternaDisponible"
      type="button"
      variant="secondary"
      size="icon-lg"
      class="absolute top-3 right-3 rounded-full"
      :aria-pressed="linternaEncendida"
      @click="alternarLinterna"
    >
      <BoltIcon class="size-5" />
      <span class="sr-only">Linterna</span>
    </Button>

    <p
      v-if="aviso"
      class="absolute inset-x-0 bottom-0 bg-black/70 px-4 py-3 text-center text-sm text-white"
    >
      {{ aviso }}
    </p>

    <!-- Sin cámara en vivo el escáner no se rinde: ofrece la foto, que usa el mismo lector. -->
    <div
      v-if="!camaraEnVivo"
      class="flex min-h-64 flex-col items-center justify-center gap-4 p-6 text-center"
    >
      <p class="text-sm text-white/90">
        {{ error ?? 'Preparando la cámara...' }}
      </p>
      <Button
        type="button"
        variant="secondary"
        class="h-14 px-6 text-base"
        :disabled="leyendoFoto"
        @click="entradaFoto?.click()"
      >
        <CameraIcon class="size-5" />
        {{ leyendoFoto ? 'Leyendo la foto...' : 'Tomar una foto de la etiqueta' }}
      </Button>
      <input
        ref="entradaFoto"
        type="file"
        class="hidden"
        accept="image/*"
        capture="environment"
        @change="onFoto"
      />
    </div>
  </div>
</template>
