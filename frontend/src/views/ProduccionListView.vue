<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ClockIcon } from '@heroicons/vue/24/outline'
import {
  useOrdenesTrabajoStore,
  ESTADOS_TABLERO,
  type OrdenTrabajo,
} from '../stores/ordenesTrabajo'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader } from '../components/ui/card'
import { Badge } from '../components/ui/badge'
import { Alert, AlertDescription } from '../components/ui/alert'

/**
 * Tablero de Producción (ver 038-produccion-ordenes-trabajo.md): "¿qué tengo pendiente, qué estoy
 * fabricando, qué ya está listo, qué debo entregar y qué está pendiente de envío?".
 *
 * Por defecto excluye lo ya entregado — es el tablero de lo que falta, no un historial — con un
 * botón para ver el historial aparte.
 */
const ordenesTrabajo = useOrdenesTrabajoStore()

const cargando = ref(true)
const error = ref<string | null>(null)
const verEntregadas = ref(false)
const accionando = ref<number | null>(null)

const ESTADO_TEXTO: Record<string, string> = {
  pendiente: 'Pendiente',
  en_produccion: 'En producción',
  listo_para_entregar: 'Listo para entregar',
  a_domicilio: 'A domicilio',
  entregado: 'Entregado',
}

const ESTADO_VARIANTE: Record<string, 'secondary' | 'warning' | 'success' | 'default' | 'outline'> =
  {
    pendiente: 'secondary',
    en_produccion: 'warning',
    listo_para_entregar: 'success',
    a_domicilio: 'default',
    entregado: 'outline',
  }

async function cargar() {
  cargando.value = true
  error.value = null
  ordenesTrabajo.filtroEstado = verEntregadas.value ? ['entregado'] : [...ESTADOS_TABLERO]

  try {
    await ordenesTrabajo.fetchList()
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
}

function irAPagina(pagina: number) {
  ordenesTrabajo.fetchList(pagina)
}

async function alternarVista() {
  verEntregadas.value = !verEntregadas.value
  await cargar()
}

async function iniciarProduccion(orden: OrdenTrabajo) {
  accionando.value = orden.id
  error.value = null
  try {
    await ordenesTrabajo.iniciarProduccion(orden.id)
    await cargar()
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    accionando.value = null
  }
}

async function marcarListo(orden: OrdenTrabajo) {
  accionando.value = orden.id
  error.value = null
  try {
    await ordenesTrabajo.marcarListo(orden.id)
    await cargar()
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    accionando.value = null
  }
}

onMounted(cargar)
</script>

<template>
  <AppLayout>
    <div class="mx-auto max-w-6xl space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="font-heading text-foreground text-xl font-semibold">Producción</h1>
        <Button variant="outline" size="sm" @click="alternarVista">
          <ClockIcon class="size-4" />
          {{ verEntregadas ? 'Ver tablero' : 'Ver entregadas' }}
        </Button>
      </div>

      <Alert v-if="error" variant="destructive">
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>

      <p v-if="cargando" class="text-muted-foreground text-center">Cargando...</p>

      <template v-else>
        <p v-if="ordenesTrabajo.items.length === 0" class="text-muted-foreground text-center">
          {{ verEntregadas ? 'Todavía no hay órdenes entregadas.' : 'No hay trabajos pendientes.' }}
        </p>

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <RouterLink
            v-for="orden in ordenesTrabajo.items"
            :key="orden.id"
            :to="{ name: 'produccion-detalle', params: { id: orden.id } }"
          >
            <Card class="hover:border-primary/50 h-full transition-colors">
              <CardHeader class="flex flex-row items-start justify-between gap-2 space-y-0">
                <div>
                  <p class="font-mono text-sm font-semibold">{{ orden.folio_formateado }}</p>
                  <p class="text-foreground font-medium">{{ orden.cliente_nombre }}</p>
                </div>
                <Badge :variant="ESTADO_VARIANTE[orden.estado]">
                  {{ ESTADO_TEXTO[orden.estado] }}
                </Badge>
              </CardHeader>
              <CardContent class="space-y-3">
                <img
                  v-if="orden.imagen_url"
                  :src="orden.imagen_url"
                  alt="Imagen del diseño"
                  class="bg-muted h-32 w-full rounded-md object-cover"
                />
                <p class="text-muted-foreground text-sm">{{ orden.producto }}</p>
                <div class="flex items-center justify-between text-sm">
                  <span class="text-muted-foreground">{{ orden.documento_etiqueta }}</span>
                  <span v-if="orden.saldo_pendiente > 0" class="font-medium">
                    Saldo: ${{ orden.saldo_pendiente.toFixed(2) }}
                  </span>
                </div>

                <Button
                  v-if="orden.estado === 'pendiente'"
                  class="w-full"
                  size="sm"
                  :disabled="accionando === orden.id"
                  @click.prevent="iniciarProduccion(orden)"
                >
                  Iniciar producción
                </Button>
                <Button
                  v-else-if="orden.estado === 'en_produccion'"
                  class="w-full"
                  size="sm"
                  :disabled="accionando === orden.id"
                  @click.prevent="marcarListo(orden)"
                >
                  Marcar como listo
                </Button>
              </CardContent>
            </Card>
          </RouterLink>
        </div>

        <div
          v-if="ordenesTrabajo.meta && ordenesTrabajo.meta.last_page > 1"
          class="flex justify-center gap-2"
        >
          <Button
            variant="outline"
            size="sm"
            :disabled="ordenesTrabajo.meta.current_page <= 1"
            @click="irAPagina(ordenesTrabajo.meta.current_page - 1)"
          >
            Anterior
          </Button>
          <span class="text-muted-foreground self-center text-sm">
            Página {{ ordenesTrabajo.meta.current_page }} de {{ ordenesTrabajo.meta.last_page }}
          </span>
          <Button
            variant="outline"
            size="sm"
            :disabled="ordenesTrabajo.meta.current_page >= ordenesTrabajo.meta.last_page"
            @click="irAPagina(ordenesTrabajo.meta.current_page + 1)"
          >
            Siguiente
          </Button>
        </div>
      </template>
    </div>
  </AppLayout>
</template>
