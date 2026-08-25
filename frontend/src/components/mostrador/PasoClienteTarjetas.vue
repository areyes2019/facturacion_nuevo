<script setup lang="ts">
import { ref, useTemplateRef } from 'vue'
import axios from 'axios'
import { useDebounceFn } from '@vueuse/core'
import { ArrowUpTrayIcon, QrCodeIcon, UserPlusIcon } from '@heroicons/vue/24/outline'
import http from '../../lib/http'
import { mensajeDeFalla } from '../../lib/errors'
import { vibrarLectura } from '../../lib/lectorQr'
import { useScrollInfinito } from '../../lib/scrollInfinito'
import { useClientesStore, type Cliente } from '../../stores/clientes'
import type { CamposConstancia, ResultadoConstancia } from '../../lib/constanciaFiscal'
import type { ClienteResultado } from '../ClienteCombobox.vue'
import ConstanciaFiscalDropzone from '../ConstanciaFiscalDropzone.vue'
import EscanerQr from '../EscanerQr.vue'
import RegimenFiscalSelect from '../RegimenFiscalSelect.vue'
import { Alert, AlertDescription } from '../ui/alert'
import { Button } from '../ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../ui/dialog'
import { Input } from '../ui/input'
import { Label } from '../ui/label'

/**
 * El paso de cliente de la factura y de la cotización (ver 029-pwa-mostrador.md).
 *
 * Tres caminos hacia el mismo lugar: elegir de la lista, subir la constancia o capturarlo a mano.
 * La lista se ve **desde que abre la pantalla**, sin escribir nada, porque el combo de escritorio
 * obliga a recordar cómo empieza el nombre y aquí lo común es reconocer al cliente al verlo.
 *
 * Tocar una tarjeta elige y avanza: apretar un "Siguiente" después de haber tocado al cliente es un
 * toque que no decide nada.
 */

const props = withDefaults(defineProps<{ recomendarConstancia?: boolean }>(), {
  recomendarConstancia: false,
})

const emit = defineEmits<{ elegido: [ClienteResultado] }>()

const clientesStore = useClientesStore()

const texto = ref('')
const clientes = ref<Cliente[]>([])
const pagina = ref(1)
const ultimaPagina = ref(1)
const cargando = ref(false)
const error = ref<string | null>(null)

/** Lo que responde `POST clientes/constancia` por el camino de la cámara (ver 016). */
interface RespuestaConstancia {
  data: CamposConstancia
  cliente_existente: { id: number; razon_social: string } | null
}

const MENSAJES_ERROR: Record<string, string> = {
  QR_NO_OFICIAL: 'Ese código no es el de una Constancia de Situación Fiscal.',
  SAT_NO_DISPONIBLE: 'El SAT no responde. Da de alta al cliente desde la computadora.',
  LIMITE: 'Vas muy rápido. Espera un momento antes de escanear otra constancia.',
}

async function cargar(siguiente: number) {
  if (cargando.value) return

  cargando.value = true
  error.value = null

  try {
    const { data } = await http.get('/clientes', {
      params: { page: siguiente, search: texto.value.trim() || undefined },
    })

    clientes.value = siguiente === 1 ? data.data : [...clientes.value, ...data.data]
    pagina.value = data.meta.current_page
    ultimaPagina.value = data.meta.last_page
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    cargando.value = false
  }
}

void cargar(1)

// La búsqueda no se hace en el navegador sobre lo ya cargado: el catálogo llega paginado y filtrar
// solo la página que se tiene a la vista escondería clientes que sí existen.
const buscar = useDebounceFn(() => cargar(1), 300)

const centinela = useTemplateRef<HTMLElement>('centinela')

useScrollInfinito(centinela, () => {
  if (pagina.value < ultimaPagina.value) void cargar(pagina.value + 1)
})

function elegir(cliente: Cliente | ClienteResultado) {
  emit('elegido', {
    id: cliente.id,
    razon_social: cliente.razon_social,
    rfc: cliente.rfc,
    descuento_permanente: cliente.descuento_permanente,
    es_distribuidor: cliente.es_distribuidor,
  })
}

// --- Alta por constancia: archivo o cámara ---

const subiendoRfc = ref(false)
const modoConstancia = ref<'archivo' | 'camara'>('archivo')
const procesando = ref(false)
const avisoConstancia = ref<string | null>(null)

function abrirSubirRfc() {
  modoConstancia.value = 'archivo'
  avisoConstancia.value = null
  subiendoRfc.value = true
}

/** El archivo lo procesa el dropzone de 016, que lee el QR en el navegador y no sube el PDF. */
async function onDatosExtraidos(resultado: ResultadoConstancia) {
  if (resultado.clienteExistente) {
    await usarExistente(resultado.clienteExistente.id)
    return
  }

  await darDeAlta(resultado.campos)
}

async function onCodigo(codigo: string) {
  if (procesando.value) return

  procesando.value = true
  avisoConstancia.value = null

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

      avisoConstancia.value =
        MENSAJES_ERROR[clave] ?? 'No se pudieron obtener los datos de esa constancia.'
      return
    }

    avisoConstancia.value = mensajeDeFalla(err)
  } finally {
    procesando.value = false
  }
}

async function usarExistente(id: number) {
  const encontrado = await clientesStore.fetchOne(id)

  subiendoRfc.value = false
  elegir(encontrado)
}

/**
 * El alta solo procede con los cuatro datos que el CFDI exige. Faltando alguno, la ficha se captura
 * a mano en el mismo aparato: media alta guardada aquí sería un cliente al que después no se le
 * puede timbrar y que nadie sabría que está incompleto.
 */
async function darDeAlta(campos: CamposConstancia) {
  const { rfc, razon_social, regimen_fiscal, codigo_postal_fiscal } = campos

  if (!rfc || !razon_social || !regimen_fiscal || !codigo_postal_fiscal) {
    avisoConstancia.value =
      'Esa constancia no trae todos los datos fiscales. Captúralos con "Nuevo cliente".'
    return
  }

  try {
    const creado = await clientesStore.create({
      rfc,
      razon_social,
      regimen_fiscal,
      codigo_postal_fiscal,
      nombre_comercial: null,
      correo_contacto: null,
      telefono: null,
      direccion_comercial: campos.direccion_comercial,
      descuento_permanente: 0,
      es_distribuidor: false,
    })

    subiendoRfc.value = false
    elegir(creado)
  } catch (err) {
    avisoConstancia.value = mensajeDeFalla(err)
  }
}

// --- Alta manual ---

const capturando = ref(false)
const guardando = ref(false)
const errorAlta = ref<string | null>(null)

const nuevo = ref({
  rfc: '',
  razon_social: '',
  regimen_fiscal: null as string | null,
  codigo_postal_fiscal: '',
  telefono: '',
  correo_contacto: '',
})

function abrirCaptura() {
  nuevo.value = {
    rfc: '',
    razon_social: '',
    regimen_fiscal: null,
    codigo_postal_fiscal: '',
    telefono: '',
    correo_contacto: '',
  }
  errorAlta.value = null
  capturando.value = true
}

/**
 * Los cuatro datos del CFDI son obligatorios aunque una cotización no se timbre: el cliente que hoy
 * pide precio es el que mañana pide factura, y una ficha a medias no se puede timbrar.
 */
async function guardarNuevo() {
  const { rfc, razon_social, regimen_fiscal, codigo_postal_fiscal } = nuevo.value

  if (!rfc.trim() || !razon_social.trim() || !regimen_fiscal || !codigo_postal_fiscal.trim()) {
    errorAlta.value = 'Faltan datos: RFC, razón social, régimen fiscal y código postal.'
    return
  }

  guardando.value = true
  errorAlta.value = null

  try {
    const creado = await clientesStore.create({
      rfc: rfc.trim().toUpperCase(),
      razon_social: razon_social.trim(),
      regimen_fiscal,
      codigo_postal_fiscal: codigo_postal_fiscal.trim(),
      nombre_comercial: null,
      correo_contacto: nuevo.value.correo_contacto.trim() || null,
      telefono: nuevo.value.telefono.trim() || null,
      direccion_comercial: null,
      descuento_permanente: 0,
      es_distribuidor: false,
    })

    capturando.value = false
    elegir(creado)
  } catch (err) {
    errorAlta.value = mensajeDeFalla(err)
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="grid grid-cols-2 gap-3">
      <Button type="button" variant="outline" class="h-16 text-base" @click="abrirSubirRfc">
        <ArrowUpTrayIcon class="size-5" />
        Subir RFC
      </Button>
      <Button type="button" variant="outline" class="h-16 text-base" @click="abrirCaptura">
        <UserPlusIcon class="size-5" />
        Nuevo cliente
      </Button>
    </div>

    <p v-if="props.recomendarConstancia" class="text-muted-foreground text-sm">
      Para facturar conviene subir la constancia: el RFC, el régimen y el código postal tienen que
      ir exactos o el timbrado sale mal.
    </p>

    <Input
      v-model="texto"
      placeholder="Buscar por nombre o RFC..."
      class="h-12 text-base"
      @update:model-value="buscar()"
    />

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <p v-if="!cargando && clientes.length === 0" class="text-muted-foreground py-8 text-center">
      No hay clientes que coincidan. Súbele la constancia o captúralo a mano.
    </p>

    <ul class="space-y-2">
      <li v-for="cliente in clientes" :key="cliente.id">
        <button
          type="button"
          class="border-border bg-background hover:bg-accent focus-visible:ring-ring w-full rounded-lg border p-4 text-left focus-visible:ring-2 focus-visible:outline-none"
          @click="elegir(cliente)"
        >
          <p class="text-foreground text-lg font-medium">{{ cliente.razon_social }}</p>
          <p class="text-muted-foreground font-mono text-sm">{{ cliente.rfc }}</p>
          <p
            v-if="cliente.telefono || cliente.correo_contacto"
            class="text-muted-foreground text-sm"
          >
            {{ [cliente.telefono, cliente.correo_contacto].filter(Boolean).join(' · ') }}
          </p>
          <p v-if="cliente.descuento_permanente > 0" class="text-muted-foreground mt-1 text-sm">
            Descuento permanente de {{ cliente.descuento_permanente }}%
          </p>
        </button>
      </li>
    </ul>

    <div v-if="cargando" class="text-muted-foreground py-4 text-center">Cargando...</div>

    <!-- El centinela solo existe cuando queda página siguiente: sin él, el observador no pide. -->
    <div v-else-if="pagina < ultimaPagina" ref="centinela" class="h-px"></div>

    <Dialog v-model:open="subiendoRfc">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Constancia de Situación Fiscal</DialogTitle>
          <DialogDescription>
            Sube el archivo que te pasó el cliente, o apunta la cámara a su código QR.
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-4">
          <div class="grid grid-cols-2 gap-2">
            <Button
              type="button"
              :variant="modoConstancia === 'archivo' ? 'default' : 'outline'"
              class="h-12"
              @click="modoConstancia = 'archivo'"
            >
              <ArrowUpTrayIcon class="size-5" />
              Archivo
            </Button>
            <Button
              type="button"
              :variant="modoConstancia === 'camara' ? 'default' : 'outline'"
              class="h-12"
              @click="modoConstancia = 'camara'"
            >
              <QrCodeIcon class="size-5" />
              Cámara
            </Button>
          </div>

          <ConstanciaFiscalDropzone
            v-if="modoConstancia === 'archivo'"
            @datos-extraidos="onDatosExtraidos"
          />

          <!-- La cámara solo se abre mientras se está mirando por ella: el `v-if` la suelta al
               cambiar de pestaña o al cerrar el diálogo. -->
          <div v-else class="h-72">
            <EscanerQr
              v-if="subiendoRfc"
              class="h-full"
              :aviso="procesando ? 'Consultando al SAT...' : avisoConstancia"
              @codigo="onCodigo"
            />
          </div>

          <Alert v-if="modoConstancia === 'archivo' && avisoConstancia" variant="warning">
            <AlertDescription>{{ avisoConstancia }}</AlertDescription>
          </Alert>
        </div>
      </DialogContent>
    </Dialog>

    <Dialog v-model:open="capturando">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Nuevo cliente</DialogTitle>
          <DialogDescription>
            Los cuatro primeros son los que exige una factura. El teléfono y el correo son para
            mandarle el documento.
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-3">
          <div class="space-y-1.5">
            <Label for="nuevo-rfc">RFC</Label>
            <Input id="nuevo-rfc" v-model="nuevo.rfc" class="h-12 text-base uppercase" />
          </div>
          <div class="space-y-1.5">
            <Label for="nuevo-razon">Razón social</Label>
            <Input id="nuevo-razon" v-model="nuevo.razon_social" class="h-12 text-base" />
          </div>
          <div class="space-y-1.5">
            <Label>Régimen fiscal</Label>
            <RegimenFiscalSelect v-model="nuevo.regimen_fiscal" />
          </div>
          <div class="space-y-1.5">
            <Label for="nuevo-cp">Código postal fiscal</Label>
            <Input
              id="nuevo-cp"
              v-model="nuevo.codigo_postal_fiscal"
              inputmode="numeric"
              class="h-12 text-base"
            />
          </div>
          <div class="space-y-1.5">
            <Label for="nuevo-telefono">Teléfono</Label>
            <Input
              id="nuevo-telefono"
              v-model="nuevo.telefono"
              inputmode="tel"
              class="h-12 text-base"
            />
          </div>
          <div class="space-y-1.5">
            <Label for="nuevo-correo">Correo</Label>
            <Input
              id="nuevo-correo"
              v-model="nuevo.correo_contacto"
              inputmode="email"
              class="h-12 text-base"
            />
          </div>

          <Alert v-if="errorAlta" variant="destructive">
            <AlertDescription>{{ errorAlta }}</AlertDescription>
          </Alert>
        </div>

        <DialogFooter>
          <Button variant="outline" @click="capturando = false">Cancelar</Button>
          <Button :disabled="guardando" @click="guardarNuevo">
            {{ guardando ? 'Guardando...' : 'Guardar y elegir' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
