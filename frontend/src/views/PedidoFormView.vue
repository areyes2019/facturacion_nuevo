<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { usePedidosStore, type PedidoPayload } from '../stores/pedidos'
import type { TipoDescuento } from '../stores/facturas'
import { calcularTotales } from '../lib/totalesDocumento'
import { extractErrorMessage, extractFieldErrors } from '../lib/errors'
import { Button } from '../components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import AppLayout from '../layouts/AppLayout.vue'
import DocumentoLineas, { type LineaEditable } from '../components/DocumentoLineas.vue'

const route = useRoute()
const router = useRouter()
const pedidos = usePedidosStore()

const pedidoId = computed(() => {
  const id = route.params.id
  return typeof id === 'string' ? Number(id) : null
})
const esEdicion = computed(() => pedidoId.value !== null)

const form = reactive({
  cliente_nombre: '',
  cliente_telefono: '',
  cliente_correo: '',
  descuento_global_tipo: null as TipoDescuento | null,
  descuento_global_valor: null as number | null,
})

const lineas = ref<LineaEditable[]>([])

const cargando = ref(false)
const guardando = ref(false)
const errorGeneral = ref<string | null>(null)
const erroresPorCampo = ref<Record<string, string>>({})

/**
 * Datos del último pedido con este teléfono. Es una **sugerencia**: se muestra y el usuario decide
 * si la aplica, en vez de pisar lo que esté escribiendo.
 */
const sugerencia = ref<{ cliente_nombre: string; cliente_correo: string | null } | null>(null)

const totales = computed(() =>
  calcularTotales(lineas.value, form.descuento_global_tipo, form.descuento_global_valor, true),
)

onMounted(async () => {
  if (!pedidoId.value) return

  cargando.value = true
  try {
    const pedido = await pedidos.fetchOne(pedidoId.value)
    form.cliente_nombre = pedido.cliente_nombre
    form.cliente_telefono = pedido.cliente_telefono
    form.cliente_correo = pedido.cliente_correo ?? ''
    form.descuento_global_tipo = pedido.descuento_global_tipo
    form.descuento_global_valor = pedido.descuento_global_valor
    lineas.value = pedido.lineas.map((l) => ({
      articulo_id: l.articulo_id,
      cantidad: l.cantidad,
      descripcion: l.descripcion,
      modelo: l.modelo ?? '',
      precio_unitario: l.precio_unitario,
      descuento_tipo: l.descuento_tipo,
      descuento_valor: l.descuento_valor,
      tasa_iva: l.tasa_iva,
    }))
  } catch (err) {
    errorGeneral.value = extractErrorMessage(err)
  } finally {
    cargando.value = false
  }
})

/**
 * Al terminar de capturar el teléfono se busca si ese número ya compró antes. No se dispara en cada
 * tecla: sería una petición por dígito para responder algo que solo tiene sentido con el número
 * completo.
 */
async function buscarClienteAnterior() {
  sugerencia.value = null

  const telefono = form.cliente_telefono.trim()
  if (esEdicion.value || telefono.length < 8) return

  try {
    const resultado = await pedidos.buscarPorTelefono(telefono)

    if (resultado.encontrado && resultado.cliente_nombre) {
      sugerencia.value = {
        cliente_nombre: resultado.cliente_nombre,
        cliente_correo: resultado.cliente_correo ?? null,
      }
    }
  } catch {
    // Una sugerencia que falla no puede estorbar la captura de la venta.
  }
}

function aplicarSugerencia() {
  if (!sugerencia.value) return

  form.cliente_nombre = sugerencia.value.cliente_nombre
  form.cliente_correo = sugerencia.value.cliente_correo ?? ''
  sugerencia.value = null
}

async function onSubmit() {
  guardando.value = true
  errorGeneral.value = null
  erroresPorCampo.value = {}

  const payload: PedidoPayload = {
    cliente_nombre: form.cliente_nombre.trim(),
    cliente_telefono: form.cliente_telefono.trim(),
    cliente_correo: form.cliente_correo.trim() || null,
    descuento_global_tipo: form.descuento_global_tipo,
    descuento_global_valor: form.descuento_global_valor,
    lineas: lineas.value.map((l) => ({
      articulo_id: l.articulo_id,
      cantidad: l.cantidad,
      descripcion: l.descripcion,
      modelo: l.modelo || null,
      precio_unitario: l.precio_unitario,
      descuento_tipo: l.descuento_tipo,
      descuento_valor: l.descuento_valor,
      tasa_iva: l.tasa_iva,
    })),
    total: totales.value.total,
  }

  try {
    const pedido =
      esEdicion.value && pedidoId.value
        ? await pedidos.update(pedidoId.value, payload)
        : await pedidos.create(payload)

    await router.push({ name: 'pedidos-detalle', params: { id: pedido.id } })
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
    <div class="mx-auto max-w-4xl space-y-4">
      <h1 class="font-heading text-foreground text-xl font-semibold">
        {{ esEdicion ? 'Editar pedido' : 'Nuevo pedido de mostrador' }}
      </h1>

      <Alert v-if="errorGeneral" variant="destructive">
        <AlertDescription>{{ errorGeneral }}</AlertDescription>
      </Alert>

      <form v-if="!cargando" class="space-y-6" @submit.prevent="onSubmit">
        <Card>
          <CardHeader>
            <CardTitle class="text-base">Cliente</CardTitle>
          </CardHeader>
          <CardContent class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
              <Label for="cliente_telefono">Teléfono</Label>
              <Input
                id="cliente_telefono"
                v-model="form.cliente_telefono"
                inputmode="tel"
                autocomplete="off"
                @blur="buscarClienteAnterior"
              />
              <p v-if="erroresPorCampo.cliente_telefono" class="text-destructive text-sm">
                {{ erroresPorCampo.cliente_telefono }}
              </p>
            </div>

            <div class="space-y-1.5">
              <Label for="cliente_nombre">Nombre</Label>
              <Input id="cliente_nombre" v-model="form.cliente_nombre" autocomplete="off" />
              <p v-if="erroresPorCampo.cliente_nombre" class="text-destructive text-sm">
                {{ erroresPorCampo.cliente_nombre }}
              </p>
            </div>

            <div class="space-y-1.5 sm:col-span-2">
              <Label for="cliente_correo">Correo (opcional)</Label>
              <Input
                id="cliente_correo"
                v-model="form.cliente_correo"
                type="email"
                autocomplete="off"
              />
              <p v-if="erroresPorCampo.cliente_correo" class="text-destructive text-sm">
                {{ erroresPorCampo.cliente_correo }}
              </p>
            </div>

            <Alert v-if="sugerencia" class="sm:col-span-2">
              <AlertDescription class="flex flex-wrap items-center gap-2">
                <span class="min-w-0">
                  Este teléfono ya compró antes, a nombre de
                  <strong>{{ sugerencia.cliente_nombre }}</strong
                  >.
                </span>
                <Button type="button" size="sm" variant="outline" @click="aplicarSugerencia">
                  Usar esos datos
                </Button>
                <Button type="button" size="sm" variant="ghost" @click="sugerencia = null">
                  Ignorar
                </Button>
              </AlertDescription>
            </Alert>
          </CardContent>
        </Card>

        <DocumentoLineas
          v-model:lineas="lineas"
          v-model:descuento-global-tipo="form.descuento_global_tipo"
          v-model:descuento-global-valor="form.descuento_global_valor"
          :error-lineas="erroresPorCampo.lineas"
          permite-linea-libre
          redondear-al-peso
        />

        <div class="flex justify-end gap-2">
          <Button type="button" variant="outline" @click="router.push({ name: 'pedidos' })">
            Cancelar
          </Button>
          <Button type="submit" :disabled="guardando || lineas.length === 0">
            {{ guardando ? 'Guardando...' : 'Guardar pedido' }}
          </Button>
        </div>
      </form>
    </div>
  </AppLayout>
</template>
