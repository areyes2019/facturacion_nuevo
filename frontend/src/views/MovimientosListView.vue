<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import {
  ArrowsRightLeftIcon,
  ArrowTrendingDownIcon,
  ArrowTrendingUpIcon,
  PencilIcon,
  TrashIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'
import {
  useMovimientosStore,
  TIPOS_MOVIMIENTO,
  type Movimiento,
  type TipoMovimiento,
} from '../stores/movimientos'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import CuentaSelect from '../components/CuentaSelect.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Badge } from '../components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '../components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '../components/ui/dialog'

type TipoManual = 'ingreso' | 'egreso' | 'ajuste'

const route = useRoute()
const movimientos = useMovimientosStore()

const hoy = () => new Date().toISOString().slice(0, 10)

onMounted(() => {
  // La consulta de saldos enlaza a esta vista ya filtrada por una cuenta (UC-07).
  const cuentaId = route.query.cuenta_id
  movimientos.filtroCuentaId = typeof cuentaId === 'string' ? Number(cuentaId) : null

  movimientos.fetchList()
})

let buscarTimeout: ReturnType<typeof setTimeout>
function onBuscarConcepto() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => movimientos.fetchList(1), 300)
}

function aplicarFiltros() {
  movimientos.fetchList(1)
}

function limpiarFiltros() {
  movimientos.limpiarFiltros()
  movimientos.fetchList(1)
}

function irAPagina(pagina: number) {
  movimientos.fetchList(pagina)
}

function moneda(valor: number) {
  const signo = valor < 0 ? '-' : '+'
  return `${signo}$${Math.abs(valor).toFixed(2)}`
}

// El color sigue el efecto sobre el saldo, no el tipo: una transferencia es verde en la cuenta
// destino y roja en la origen, aunque ambas filas compartan tipo.
function colorMonto(movimiento: Movimiento) {
  return movimiento.efecto_en_saldo < 0 ? 'text-destructive' : 'text-emerald-600'
}

function tipoVariant(tipo: TipoMovimiento) {
  return {
    ingreso: 'success',
    egreso: 'destructive',
    transferencia: 'secondary',
    ajuste: 'warning',
  }[tipo] as 'success' | 'destructive' | 'secondary' | 'warning'
}

// --- Modal de ingreso / egreso / ajuste (UC-02, UC-03, UC-05) -------------------------------

const mostrarMovimiento = ref(false)
const tipoMovimiento = ref<TipoManual>('ingreso')
const movimientoEnEdicion = ref<number | null>(null)
const guardando = ref(false)
const errorMovimiento = ref<string | null>(null)

const formMovimiento = reactive({
  cuenta_id: null as number | null,
  monto: null as number | null,
  fecha: hoy(),
  concepto: '',
})

const tituloMovimiento = computed(() => {
  const accion = movimientoEnEdicion.value ? 'Editar' : 'Registrar'
  return `${accion} ${{ ingreso: 'ingreso', egreso: 'egreso', ajuste: 'ajuste' }[tipoMovimiento.value]}`
})

function abrirMovimiento(tipo: TipoManual) {
  tipoMovimiento.value = tipo
  movimientoEnEdicion.value = null
  formMovimiento.cuenta_id = null
  formMovimiento.monto = null
  formMovimiento.fecha = hoy()
  formMovimiento.concepto = ''
  errorMovimiento.value = null
  mostrarMovimiento.value = true
}

function abrirEditar(movimiento: Movimiento) {
  tipoMovimiento.value = movimiento.tipo as TipoManual
  movimientoEnEdicion.value = movimiento.id
  formMovimiento.cuenta_id = movimiento.cuenta_id
  formMovimiento.monto = movimiento.monto
  formMovimiento.fecha = movimiento.fecha
  formMovimiento.concepto = movimiento.concepto
  errorMovimiento.value = null
  mostrarMovimiento.value = true
}

async function guardarMovimiento() {
  guardando.value = true
  errorMovimiento.value = null

  const payload = {
    tipo: tipoMovimiento.value,
    cuenta_id: formMovimiento.cuenta_id,
    monto: formMovimiento.monto,
    fecha: formMovimiento.fecha,
    concepto: formMovimiento.concepto,
  }

  try {
    if (movimientoEnEdicion.value) {
      await movimientos.update(movimientoEnEdicion.value, payload)
    } else {
      await movimientos.create(payload)
    }
    mostrarMovimiento.value = false
    await movimientos.fetchList(movimientos.meta?.current_page ?? 1)
  } catch (err) {
    errorMovimiento.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
  }
}

// --- Modal de transferencia (UC-04) ---------------------------------------------------------

const mostrarTransferencia = ref(false)
const errorTransferencia = ref<string | null>(null)

const formTransferencia = reactive({
  cuenta_origen_id: null as number | null,
  cuenta_destino_id: null as number | null,
  monto: null as number | null,
  fecha: hoy(),
  concepto: '',
})

function abrirTransferencia() {
  formTransferencia.cuenta_origen_id = null
  formTransferencia.cuenta_destino_id = null
  formTransferencia.monto = null
  formTransferencia.fecha = hoy()
  formTransferencia.concepto = ''
  errorTransferencia.value = null
  mostrarTransferencia.value = true
}

async function guardarTransferencia() {
  guardando.value = true
  errorTransferencia.value = null
  try {
    await movimientos.crearTransferencia({ ...formTransferencia })
    mostrarTransferencia.value = false
    await movimientos.fetchList(1)
  } catch (err) {
    errorTransferencia.value = extractErrorMessage(err)
  } finally {
    guardando.value = false
  }
}

// --- Eliminación ----------------------------------------------------------------------------

const movimientoAEliminar = ref<Movimiento | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)

function abrirEliminar(movimiento: Movimiento) {
  movimientoAEliminar.value = movimiento
  errorEliminar.value = null
}

async function confirmarEliminar() {
  if (!movimientoAEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await movimientos.remove(movimientoAEliminar.value.id)
    movimientoAEliminar.value = null
    await movimientos.fetchList(movimientos.meta?.current_page ?? 1)
  } catch (err) {
    errorEliminar.value = extractErrorMessage(err)
  } finally {
    eliminando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">Movimientos</h1>
        <div class="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" @click="abrirMovimiento('ingreso')">
            <ArrowTrendingUpIcon class="size-4" />
            Registrar ingreso
          </Button>
          <Button variant="outline" size="sm" @click="abrirMovimiento('egreso')">
            <ArrowTrendingDownIcon class="size-4" />
            Registrar egreso
          </Button>
          <Button variant="outline" size="sm" @click="abrirTransferencia">
            <ArrowsRightLeftIcon class="size-4" />
            Registrar transferencia
          </Button>
          <Button variant="outline" size="sm" @click="abrirMovimiento('ajuste')">
            <WrenchScrewdriverIcon class="size-4" />
            Registrar ajuste
          </Button>
        </div>
      </div>

      <Card>
        <CardContent class="grid gap-3 pt-6 sm:grid-cols-2 lg:grid-cols-5">
          <div class="space-y-1.5">
            <Label for="fecha_desde">Desde</Label>
            <Input
              id="fecha_desde"
              v-model="movimientos.filtroFechaDesde"
              type="date"
              @change="aplicarFiltros"
            />
          </div>
          <div class="space-y-1.5">
            <Label for="fecha_hasta">Hasta</Label>
            <Input
              id="fecha_hasta"
              v-model="movimientos.filtroFechaHasta"
              type="date"
              @change="aplicarFiltros"
            />
          </div>
          <div class="space-y-1.5">
            <Label>Cuenta</Label>
            <!-- El filtro incluye cuentas inactivas: su historial sigue siendo consultable. -->
            <CuentaSelect
              v-model="movimientos.filtroCuentaId"
              :solo-activas="false"
              placeholder="Todas"
              @update:model-value="aplicarFiltros"
            />
          </div>
          <div class="space-y-1.5">
            <Label for="tipo">Tipo</Label>
            <select
              id="tipo"
              v-model="movimientos.filtroTipo"
              class="border-input h-9 w-full rounded-md border bg-transparent px-2 text-sm"
              @change="aplicarFiltros"
            >
              <option value="">Todos</option>
              <option v-for="tipo in TIPOS_MOVIMIENTO" :key="tipo.id" :value="tipo.id">
                {{ tipo.texto }}
              </option>
            </select>
          </div>
          <div class="space-y-1.5">
            <Label for="concepto">Concepto</Label>
            <Input
              id="concepto"
              v-model="movimientos.filtroConcepto"
              placeholder="Buscar..."
              @update:model-value="onBuscarConcepto"
            />
          </div>
          <div class="flex items-end">
            <Button variant="ghost" size="sm" @click="limpiarFiltros">Limpiar filtros</Button>
          </div>
        </CardContent>
      </Card>

      <Alert v-if="movimientos.error" variant="destructive">
        <AlertDescription>{{ movimientos.error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Fecha</TableHead>
                <TableHead>Cuenta</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead>Concepto</TableHead>
                <TableHead>Origen</TableHead>
                <TableHead class="text-right">Monto</TableHead>
                <TableHead class="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!movimientos.loading && movimientos.items.length === 0">
                <TableCell colspan="7" class="text-muted-foreground py-10 text-center">
                  No hay movimientos que coincidan con los filtros.
                </TableCell>
              </TableRow>
              <TableRow
                v-for="movimiento in movimientos.items"
                :key="movimiento.id"
                :class="movimiento.es_automatico ? 'bg-muted/40' : undefined"
              >
                <TableCell>{{ movimiento.fecha }}</TableCell>
                <TableCell>{{ movimiento.cuenta_nombre ?? '—' }}</TableCell>
                <TableCell>
                  <Badge :variant="tipoVariant(movimiento.tipo)">
                    {{ movimiento.tipo_texto }}
                  </Badge>
                </TableCell>
                <TableCell>{{ movimiento.concepto }}</TableCell>
                <TableCell>
                  <RouterLink
                    v-if="movimiento.documento_origen"
                    :to="{
                      name: movimiento.documento_origen.ruta,
                      params: { id: movimiento.documento_origen.id },
                    }"
                    class="text-primary hover:underline"
                  >
                    {{ movimiento.documento_origen.etiqueta }}
                  </RouterLink>
                  <span v-else class="text-muted-foreground">Manual</span>
                </TableCell>
                <TableCell class="text-right font-medium" :class="colorMonto(movimiento)">
                  {{ moneda(movimiento.efecto_en_saldo) }}
                </TableCell>
                <TableCell class="text-right">
                  <div class="flex justify-end gap-2">
                    <Button
                      variant="outline"
                      size="icon-sm"
                      :disabled="movimiento.es_automatico || movimiento.tipo === 'transferencia'"
                      :title="
                        movimiento.es_automatico
                          ? 'Los movimientos automáticos se corrigen desde su documento origen'
                          : movimiento.tipo === 'transferencia'
                            ? 'Una transferencia se corrige eliminándola y registrándola de nuevo'
                            : 'Editar'
                      "
                      @click="abrirEditar(movimiento)"
                    >
                      <PencilIcon class="size-4" />
                      <span class="sr-only">Editar</span>
                    </Button>
                    <Button
                      variant="outline"
                      size="icon-sm"
                      :disabled="movimiento.es_automatico"
                      :title="
                        movimiento.es_automatico
                          ? 'Los movimientos automáticos se corrigen desde su documento origen'
                          : 'Eliminar'
                      "
                      @click="abrirEliminar(movimiento)"
                    >
                      <TrashIcon class="size-4" />
                      <span class="sr-only">Eliminar</span>
                    </Button>
                  </div>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div
        v-if="movimientos.meta && movimientos.meta.last_page > 1"
        class="flex justify-center gap-2"
      >
        <Button
          variant="outline"
          size="sm"
          :disabled="movimientos.meta.current_page <= 1"
          @click="irAPagina(movimientos.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ movimientos.meta.current_page }} de {{ movimientos.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="movimientos.meta.current_page >= movimientos.meta.last_page"
          @click="irAPagina(movimientos.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="mostrarMovimiento" @update:open="(v) => (mostrarMovimiento = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{{ tituloMovimiento }}</DialogTitle>
            <DialogDescription v-if="tipoMovimiento === 'ajuste'">
              Un ajuste corrige el saldo de la cuenta: usa un monto negativo si el saldo real es
              menor que el registrado.
            </DialogDescription>
          </DialogHeader>
          <div class="min-w-0 space-y-4">
            <div class="space-y-1.5">
              <Label>Cuenta</Label>
              <CuentaSelect v-model="formMovimiento.cuenta_id" />
            </div>
            <div class="space-y-1.5">
              <Label for="monto">{{ tipoMovimiento === 'ajuste' ? 'Monto (±)' : 'Monto' }}</Label>
              <Input
                id="monto"
                :model-value="formMovimiento.monto ?? undefined"
                type="number"
                :min="tipoMovimiento === 'ajuste' ? undefined : '0.01'"
                step="0.01"
                @update:model-value="(v) => (formMovimiento.monto = v === '' ? null : Number(v))"
              />
            </div>
            <div class="space-y-1.5">
              <Label for="fecha">Fecha</Label>
              <Input id="fecha" v-model="formMovimiento.fecha" type="date" />
            </div>
            <div class="space-y-1.5">
              <Label for="concepto_mov">
                {{ tipoMovimiento === 'ajuste' ? 'Motivo' : 'Concepto' }}
              </Label>
              <Input id="concepto_mov" v-model="formMovimiento.concepto" />
            </div>
          </div>
          <Alert v-if="errorMovimiento" variant="destructive">
            <AlertDescription>{{ errorMovimiento }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="guardando" @click="mostrarMovimiento = false">
              Cancelar
            </Button>
            <Button :disabled="guardando" @click="guardarMovimiento">
              {{ guardando ? 'Guardando...' : 'Guardar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog :open="mostrarTransferencia" @update:open="(v) => (mostrarTransferencia = v)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Registrar transferencia</DialogTitle>
            <DialogDescription>
              Mueve dinero entre dos de tus cuentas. No es un ingreso ni un egreso: el total global
              no cambia.
            </DialogDescription>
          </DialogHeader>
          <div class="min-w-0 space-y-4">
            <div class="space-y-1.5">
              <Label>Cuenta origen</Label>
              <CuentaSelect v-model="formTransferencia.cuenta_origen_id" />
            </div>
            <div class="space-y-1.5">
              <Label>Cuenta destino</Label>
              <CuentaSelect v-model="formTransferencia.cuenta_destino_id" />
            </div>
            <div class="space-y-1.5">
              <Label for="monto_transf">Monto</Label>
              <Input
                id="monto_transf"
                :model-value="formTransferencia.monto ?? undefined"
                type="number"
                min="0.01"
                step="0.01"
                @update:model-value="(v) => (formTransferencia.monto = v === '' ? null : Number(v))"
              />
            </div>
            <div class="space-y-1.5">
              <Label for="fecha_transf">Fecha</Label>
              <Input id="fecha_transf" v-model="formTransferencia.fecha" type="date" />
            </div>
            <div class="space-y-1.5">
              <Label for="concepto_transf">Concepto</Label>
              <Input id="concepto_transf" v-model="formTransferencia.concepto" />
            </div>
          </div>
          <Alert v-if="errorTransferencia" variant="destructive">
            <AlertDescription>{{ errorTransferencia }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="guardando" @click="mostrarTransferencia = false">
              Cancelar
            </Button>
            <Button :disabled="guardando" @click="guardarTransferencia">
              {{ guardando ? 'Guardando...' : 'Transferir' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        :open="movimientoAEliminar !== null"
        @update:open="(v) => !v && (movimientoAEliminar = null)"
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar movimiento</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar "{{ movimientoAEliminar?.concepto }}"? El saldo de la
              cuenta se recalculará.
              <template v-if="movimientoAEliminar?.transferencia_id">
                Se eliminarán los dos movimientos de la transferencia.
              </template>
            </DialogDescription>
          </DialogHeader>
          <Alert v-if="errorEliminar" variant="destructive">
            <AlertDescription>{{ errorEliminar }}</AlertDescription>
          </Alert>
          <DialogFooter>
            <Button variant="outline" :disabled="eliminando" @click="movimientoAEliminar = null">
              Cancelar
            </Button>
            <Button variant="destructive" :disabled="eliminando" @click="confirmarEliminar">
              Eliminar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
