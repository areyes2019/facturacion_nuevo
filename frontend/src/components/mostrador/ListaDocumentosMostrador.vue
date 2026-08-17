<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useTemplateRef } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import http from '../../lib/http'
import { mensajeDeFalla } from '../../lib/errors'
import { useScrollInfinito } from '../../lib/scrollInfinito'
import { listaRecordada, recordarLista } from '../../lib/memoriaLista'
import { Alert, AlertDescription } from '../ui/alert'
import { Badge } from '../ui/badge'
import { Input } from '../ui/input'

/**
 * Las dos listas de documentos del mostrador —cotizaciones y facturas— son **la misma pantalla con
 * otra lista** (ver 031-mostrador-consulta.md), igual que uso de CFDI y forma de pago en 029.
 * Escritas dos veces se verían distintas el día que una de las dos se corrigiera.
 *
 * Arranca en **los últimos 30 días**: en cotizaciones ese es el plazo con el que una sin movimiento
 * se borra sola (ver 008), así que la lista sin filtrar es casi exactamente lo que sigue vivo; las
 * facturas heredan el plazo para que dos listas hermanas no obliguen a recordar cuál era cuál.
 *
 * **El buscador ignora la fecha**: en cuanto se escribe algo, la búsqueda sale sin límite y alcanza
 * cualquier documento, por viejo que sea. Es la salida para el cliente que pide su factura de hace
 * cuatro meses, y no hay que aprender ningún botón.
 */

export interface DocumentoResumen {
  id: number
  folio: number
  estado: string
  cliente_razon_social: string | null
  total: number
  created_at: string
}

export interface EtiquetaEstado {
  etiqueta: string
  variant: 'default' | 'secondary' | 'success' | 'warning' | 'destructive'
}

const props = defineProps<{
  /** Endpoint del listado: `/cotizaciones` o `/facturas`. */
  url: string
  /** Clave con la que esta lista recuerda dónde iba; el nombre de su ruta basta. */
  clave: string
  /** Nombre de la ruta del detalle, a donde lleva el toque en una tarjeta. */
  rutaDetalle: string
  placeholder: string
  /** Etiqueta y color de cada estado, con los mismos colores que la tabla del escritorio. */
  estados: Record<string, EtiquetaEstado>
  vacio: string
}>()

const DIAS_A_LA_VISTA = 30

const texto = ref('')
const documentos = ref<DocumentoResumen[]>([])
const pagina = ref(1)
const ultimaPagina = ref(1)
const cargando = ref(false)
const error = ref<string | null>(null)

/**
 * El día del negocio de hace 30 días, armado con la fecha local y no con `toISOString()`, que
 * pasaría por UTC y adelantaría o atrasaría un día según la hora.
 */
function desdeHace30Dias(): string {
  const fecha = new Date()
  fecha.setDate(fecha.getDate() - DIAS_A_LA_VISTA)

  const mes = String(fecha.getMonth() + 1).padStart(2, '0')
  const dia = String(fecha.getDate()).padStart(2, '0')

  return `${fecha.getFullYear()}-${mes}-${dia}`
}

async function cargar(siguiente: number) {
  if (cargando.value) return

  cargando.value = true
  error.value = null

  const busqueda = texto.value.trim()

  try {
    const { data } = await http.get(props.url, {
      params: {
        page: siguiente,
        search: busqueda || undefined,
        // Buscar alcanza cualquier fecha; sin buscar, la lista se queda en lo reciente.
        fecha_desde: busqueda === '' ? desdeHace30Dias() : undefined,
      },
    })

    documentos.value = siguiente === 1 ? data.data : [...documentos.value, ...data.data]
    pagina.value = data.meta.current_page
    ultimaPagina.value = data.meta.last_page
  } catch (err) {
    error.value = mensajeDeFalla(err)
  } finally {
    cargando.value = false
  }
}

// Al volver de un detalle la lista aparece donde se dejó; la primera vez, se trae desde cero.
const recordada = listaRecordada<DocumentoResumen>(props.clave)

if (recordada) {
  documentos.value = recordada.items
  pagina.value = recordada.pagina
  ultimaPagina.value = recordada.ultimaPagina
  texto.value = recordada.texto

  void nextTick(() => window.scrollTo(0, recordada.scrollY))
} else {
  void cargar(1)
}

onBeforeUnmount(() => {
  recordarLista<DocumentoResumen>(props.clave, {
    items: documentos.value,
    pagina: pagina.value,
    ultimaPagina: ultimaPagina.value,
    texto: texto.value,
    scrollY: window.scrollY,
  })
})

const buscar = useDebounceFn(() => cargar(1), 300)

const centinela = useTemplateRef<HTMLElement>('centinela')

useScrollInfinito(centinela, () => {
  if (pagina.value < ultimaPagina.value) void cargar(pagina.value + 1)
})

function estadoDe(documento: DocumentoResumen): EtiquetaEstado {
  return props.estados[documento.estado] ?? { etiqueta: documento.estado, variant: 'secondary' }
}

function fecha(iso: string): string {
  return new Date(iso).toLocaleDateString()
}
</script>

<template>
  <div class="space-y-4">
    <Input
      v-model="texto"
      :placeholder="placeholder"
      class="h-12 text-base"
      @update:model-value="buscar()"
    />

    <Alert v-if="error" variant="destructive">
      <AlertDescription>{{ error }}</AlertDescription>
    </Alert>

    <p v-if="!cargando && documentos.length === 0" class="text-muted-foreground py-8 text-center">
      {{ vacio }}
    </p>

    <ul class="space-y-2">
      <li v-for="documento in documentos" :key="documento.id">
        <RouterLink
          :to="{ name: rutaDetalle, params: { id: documento.id } }"
          class="border-border bg-background hover:bg-accent focus-visible:ring-ring flex w-full items-center gap-3 rounded-lg border p-3 focus-visible:ring-2 focus-visible:outline-none"
        >
          <div class="min-w-0 flex-1 space-y-0.5">
            <p class="text-foreground font-mono font-semibold">#{{ documento.folio }}</p>
            <p class="text-foreground truncate font-medium">
              {{ documento.cliente_razon_social ?? 'Sin cliente' }}
            </p>
            <div class="flex items-center gap-2">
              <span class="text-muted-foreground text-sm">{{ fecha(documento.created_at) }}</span>
              <Badge :variant="estadoDe(documento).variant">
                {{ estadoDe(documento).etiqueta }}
              </Badge>
            </div>
          </div>

          <span class="text-foreground shrink-0 text-lg font-semibold tabular-nums">
            ${{ documento.total.toFixed(2) }}
          </span>
        </RouterLink>
      </li>
    </ul>

    <div v-if="cargando" class="text-muted-foreground py-4 text-center">Cargando...</div>
    <div v-else-if="pagina < ultimaPagina" ref="centinela" class="h-px"></div>
  </div>
</template>
