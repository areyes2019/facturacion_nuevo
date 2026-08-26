<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  AdjustmentsHorizontalIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  ClipboardDocumentCheckIcon,
  ClipboardDocumentListIcon,
  ClockIcon,
  EllipsisVerticalIcon,
  PlusIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline'
import {
  MOTIVOS_MANUALES,
  useInventarioStore,
  type ArticuloOmitido,
  type Descuadre,
  type OrdenInventario,
  type RenglonInventario,
} from '../stores/inventario'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import ArticuloBuscador, { type ArticuloResultado } from '../components/ArticuloBuscador.vue'
import ProveedorSelect from '../components/ProveedorSelect.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent } from '../components/ui/card'
import { Input } from '../components/ui/input'
import { Label } from '../components/ui/label'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Badge } from '../components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '../components/ui/select'
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
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '../components/ui/dropdown-menu'

const inventario = useInventarioStore()
const router = useRouter()

onMounted(() => inventario.fetchList())

let buscarTimeout: ReturnType<typeof setTimeout>
function onBuscar() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => inventario.fetchList(1), 300)
}

function pesos(valor: number): string {
  return valor.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const columnasOrdenables: { clave: OrdenInventario; etiqueta: string; alineado?: boolean }[] = [
  { clave: 'existencia', etiqueta: 'Existencia', alineado: true },
  { clave: 'faltante', etiqueta: 'Faltante', alineado: true },
  { clave: 'invertido', etiqueta: 'Invertido', alineado: true },
  { clave: 'beneficio', etiqueta: 'Beneficio', alineado: true },
]

// ---------------------------------------------------------------------------------------------
// Ajuste manual (que es también el alta de un artículo al inventario)
// ---------------------------------------------------------------------------------------------

const articuloAAjustar = ref<{ id: number; nombre: string; modelo: string } | null>(null)
const cantidadAjuste = ref<number | null>(null)
const motivoAjuste = ref<string>('conteo_fisico')
const notaAjuste = ref('')
const guardandoAjuste = ref(false)
const errorAjuste = ref<string | null>(null)

function abrirAjuste(renglon: RenglonInventario) {
  articuloAAjustar.value = { id: renglon.id, nombre: renglon.nombre, modelo: renglon.modelo }
  cantidadAjuste.value = renglon.existencia
  motivoAjuste.value = 'conteo_fisico'
  notaAjuste.value = ''
  errorAjuste.value = null
}

/** Agregar al inventario un artículo que nunca tuvo existencia es el mismo ajuste, desde cero. */
function abrirAltaManual(articulo: ArticuloResultado) {
  articuloAAjustar.value = { id: articulo.id, nombre: articulo.nombre, modelo: articulo.modelo }
  cantidadAjuste.value = null
  motivoAjuste.value = 'entrada_inicial'
  notaAjuste.value = ''
  errorAjuste.value = null
}

async function confirmarAjuste() {
  if (!articuloAAjustar.value || cantidadAjuste.value === null) return

  guardandoAjuste.value = true
  errorAjuste.value = null
  try {
    await inventario.ajustar(
      articuloAAjustar.value.id,
      cantidadAjuste.value,
      motivoAjuste.value,
      notaAjuste.value,
    )
    articuloAAjustar.value = null
    await inventario.fetchList(inventario.meta?.current_page ?? 1)
  } catch (err) {
    errorAjuste.value = extractErrorMessage(err)
  } finally {
    guardandoAjuste.value = false
  }
}

// ---------------------------------------------------------------------------------------------
// Quitar de existencias
// ---------------------------------------------------------------------------------------------

const articuloAQuitar = ref<RenglonInventario | null>(null)
const quitando = ref(false)
const errorQuitar = ref<string | null>(null)

function abrirQuitar(renglon: RenglonInventario) {
  articuloAQuitar.value = renglon
  errorQuitar.value = null
}

async function confirmarQuitar() {
  if (!articuloAQuitar.value) return

  quitando.value = true
  errorQuitar.value = null
  try {
    await inventario.quitar(articuloAQuitar.value.id)
    articuloAQuitar.value = null
    await inventario.fetchList(inventario.meta?.current_page ?? 1)
  } catch (err) {
    errorQuitar.value = extractErrorMessage(err)
  } finally {
    quitando.value = false
  }
}

// ---------------------------------------------------------------------------------------------
// Umbrales de reposición
// ---------------------------------------------------------------------------------------------

const articuloDeUmbrales = ref<RenglonInventario | null>(null)
const minimo = ref<number>(0)
const maximo = ref<number | null>(null)
const guardandoUmbrales = ref(false)
const errorUmbrales = ref<string | null>(null)

function abrirUmbrales(renglon: RenglonInventario) {
  articuloDeUmbrales.value = renglon
  minimo.value = renglon.minimo
  maximo.value = renglon.maximo
  errorUmbrales.value = null
}

async function confirmarUmbrales() {
  if (!articuloDeUmbrales.value) return

  guardandoUmbrales.value = true
  errorUmbrales.value = null
  try {
    await inventario.guardarParametros(articuloDeUmbrales.value.id, minimo.value, maximo.value)
    articuloDeUmbrales.value = null
    await inventario.fetchList(inventario.meta?.current_page ?? 1)
  } catch (err) {
    errorUmbrales.value = extractErrorMessage(err)
  } finally {
    guardandoUmbrales.value = false
  }
}

// ---------------------------------------------------------------------------------------------
// Generación de órdenes de compra
// ---------------------------------------------------------------------------------------------

const mostrarGenerar = ref(false)
const generando = ref(false)
const errorGenerar = ref<string | null>(null)
const omitidos = ref<ArticuloOmitido[]>([])

/** Resumen de qué se va a crear, para que el usuario no confirme a ciegas. */
const resumenPorProveedor = computed(() => {
  const porProveedor = new Map<string, { articulos: number; unidades: number }>()

  for (const renglon of inventario.items.filter((item) => item.por_pedir)) {
    const proveedor = renglon.proveedor_nombre_comercial ?? 'Sin proveedor'
    const actual = porProveedor.get(proveedor) ?? { articulos: 0, unidades: 0 }
    porProveedor.set(proveedor, {
      articulos: actual.articulos + 1,
      unidades: actual.unidades + renglon.cantidad_sugerida,
    })
  }

  return [...porProveedor.entries()].map(([proveedor, datos]) => ({ proveedor, ...datos }))
})

async function confirmarGenerar() {
  generando.value = true
  errorGenerar.value = null
  omitidos.value = []
  try {
    const resultado = await inventario.generarOrdenesCompra()
    omitidos.value = resultado.omitidos

    if (resultado.omitidos.length === 0) {
      mostrarGenerar.value = false
      // Con una sola orden (un solo proveedor), se abre directo su detalle para revisarla y
      // corregirla antes de enviarla; con varias, no hay "la orden" a la que ir, así que se va a
      // la lista para elegir cuál abrir primero.
      if (resultado.ordenes.length === 1) {
        router.push({ name: 'ordenes-compra-detalle', params: { id: resultado.ordenes[0].id } })
      } else {
        router.push({ name: 'ordenes-compra' })
      }
    }
  } catch (err) {
    errorGenerar.value = extractErrorMessage(err)
  } finally {
    generando.value = false
  }
}

// ---------------------------------------------------------------------------------------------
// Auditoría
// ---------------------------------------------------------------------------------------------

const mostrarAuditoria = ref(false)
const auditando = ref(false)
const descuadres = ref<Descuadre[] | null>(null)
const errorAuditoria = ref<string | null>(null)

async function abrirAuditoria() {
  mostrarAuditoria.value = true
  descuadres.value = null
  errorAuditoria.value = null
  auditando.value = true
  try {
    descuadres.value = await inventario.auditar()
  } catch (err) {
    errorAuditoria.value = extractErrorMessage(err)
  } finally {
    auditando.value = false
  }
}
</script>

<template>
  <AppLayout>
    <div class="space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">Existencias</h1>
        <div class="flex flex-wrap gap-2">
          <Button variant="outline" @click="abrirAuditoria">
            <ClipboardDocumentCheckIcon class="size-4" />
            Auditar
          </Button>
          <Button
            variant="outline"
            :disabled="(inventario.totales?.articulos_por_pedir ?? 0) === 0"
            @click="mostrarGenerar = true"
          >
            <ClipboardDocumentListIcon class="size-4" />
            Generar órdenes de compra
          </Button>
        </div>
      </div>

      <!-- Los cuatro totales del conjunto filtrado completo: filtrar por un proveedor muestra el
           dinero invertido en ese proveedor, no en la página visible. -->
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardContent class="p-4">
            <p class="text-muted-foreground text-sm">Unidades</p>
            <p class="text-foreground text-2xl font-semibold tabular-nums">
              {{ inventario.totales?.unidades ?? 0 }}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardContent class="p-4">
            <p class="text-muted-foreground text-sm">Dinero invertido</p>
            <p class="text-foreground text-2xl font-semibold tabular-nums">
              ${{ pesos(inventario.totales?.dinero_invertido ?? 0) }}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardContent class="p-4">
            <p class="text-muted-foreground text-sm">Beneficio potencial</p>
            <p class="text-foreground text-2xl font-semibold tabular-nums">
              ${{ pesos(inventario.totales?.beneficio_potencial ?? 0) }}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardContent class="p-4">
            <p class="text-muted-foreground text-sm">Total general</p>
            <p class="text-foreground text-2xl font-semibold tabular-nums">
              ${{ pesos(inventario.totales?.total_general ?? 0) }}
            </p>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardContent class="space-y-3 p-4">
          <Label>Agregar un artículo al inventario</Label>
          <ArticuloBuscador
            origen-precio="costo"
            placeholder="Buscar artículo por nombre o modelo para capturar su existencia..."
            @seleccionar="abrirAltaManual"
          />
        </CardContent>
      </Card>

      <div class="flex flex-wrap items-end gap-3">
        <Input
          v-model="inventario.q"
          placeholder="Buscar por nombre o modelo..."
          class="max-w-xs"
          @update:model-value="onBuscar"
        />
        <div class="w-56">
          <ProveedorSelect
            v-model="inventario.proveedorId"
            @update:model-value="inventario.fetchList(1)"
          />
        </div>
        <label class="text-foreground flex items-center gap-2 text-sm">
          <input
            v-model="inventario.soloPorPedir"
            type="checkbox"
            class="border-input size-4 rounded"
            @change="inventario.fetchList(1)"
          />
          Solo por pedir
          <Badge v-if="(inventario.totales?.articulos_por_pedir ?? 0) > 0" variant="secondary">
            {{ inventario.totales?.articulos_por_pedir }}
          </Badge>
        </label>
      </div>

      <Alert v-if="inventario.error" variant="destructive">
        <AlertDescription>{{ inventario.error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Modelo</TableHead>
                <TableHead>Catálogo</TableHead>
                <TableHead
                  v-for="columna in columnasOrdenables"
                  :key="columna.clave"
                  :class="columna.alineado ? 'text-right' : undefined"
                >
                  <button
                    type="button"
                    class="hover:text-foreground -mx-1 flex items-center gap-1 rounded px-1 py-0.5"
                    :class="columna.alineado ? 'ml-auto' : undefined"
                    :aria-sort="
                      inventario.orden === columna.clave
                        ? inventario.direccion === 'asc'
                          ? 'ascending'
                          : 'descending'
                        : 'none'
                    "
                    @click="inventario.toggleOrden(columna.clave)"
                  >
                    {{ columna.etiqueta }}
                    <ChevronUpIcon
                      v-if="inventario.orden === columna.clave && inventario.direccion === 'asc'"
                      class="size-3.5"
                    />
                    <ChevronDownIcon
                      v-else-if="inventario.orden === columna.clave"
                      class="size-3.5"
                    />
                  </button>
                </TableHead>
                <TableHead class="text-right">Mín. / Máx.</TableHead>
                <TableHead class="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!inventario.loading && inventario.items.length === 0">
                <TableCell colspan="8" class="text-muted-foreground py-10 text-center">
                  Todavía no has pasado ningún artículo a existencias. Agrega uno con el buscador
                  de arriba, o márcalo desde la lista de Artículos.
                </TableCell>
              </TableRow>
              <TableRow
                v-for="renglon in inventario.items"
                :key="renglon.id"
                :class="renglon.por_pedir ? 'bg-destructive/5' : undefined"
              >
                <TableCell :title="renglon.nombre">
                  <div class="flex items-center gap-2">
                    {{ renglon.modelo }}
                    <Badge v-if="renglon.por_pedir" variant="destructive">Por pedir</Badge>
                  </div>
                </TableCell>
                <TableCell truncate :title="renglon.catalogo_nombre ?? undefined">
                  {{ renglon.catalogo_nombre ?? '—' }}
                </TableCell>
                <TableCell class="text-right tabular-nums">{{ renglon.existencia }}</TableCell>
                <TableCell class="text-right tabular-nums">
                  <span v-if="renglon.faltante_pendiente > 0" class="text-destructive font-medium">
                    {{ renglon.faltante_pendiente }}
                  </span>
                  <span v-else class="text-muted-foreground">—</span>
                </TableCell>
                <TableCell class="text-right tabular-nums">
                  ${{ pesos(renglon.dinero_invertido) }}
                </TableCell>
                <TableCell class="text-right tabular-nums">
                  ${{ pesos(renglon.beneficio_potencial) }}
                </TableCell>
                <TableCell class="text-muted-foreground text-right tabular-nums">
                  {{ renglon.minimo }} / {{ renglon.maximo ?? '—' }}
                  <span v-if="renglon.por_pedir" class="text-foreground block text-xs">
                    Pedir {{ renglon.cantidad_sugerida }}
                  </span>
                </TableCell>
                <TableCell class="text-right">
                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <Button variant="outline" size="icon-sm">
                        <EllipsisVerticalIcon class="size-4" />
                        <span class="sr-only">Acciones de {{ renglon.nombre }}</span>
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem class="gap-2" @select="abrirAjuste(renglon)">
                        <PlusIcon class="size-4 shrink-0" />
                        Ajustar existencia
                      </DropdownMenuItem>
                      <DropdownMenuItem class="gap-2" @select="abrirUmbrales(renglon)">
                        <AdjustmentsHorizontalIcon class="size-4 shrink-0" />
                        Mínimo y máximo
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        class="gap-2"
                        @select="
                          router.push({
                            name: 'existencias-movimientos',
                            params: { id: renglon.id },
                          })
                        "
                      >
                        <ClockIcon class="size-4 shrink-0" />
                        Ver movimientos
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem
                        class="gap-2"
                        variant="destructive"
                        @select="abrirQuitar(renglon)"
                      >
                        <TrashIcon class="size-4 shrink-0" />
                        Quitar de existencias
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div
        v-if="inventario.meta && inventario.meta.last_page > 1"
        class="flex justify-center gap-2"
      >
        <Button
          variant="outline"
          size="sm"
          :disabled="inventario.meta.current_page <= 1"
          @click="inventario.fetchList(inventario.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ inventario.meta.current_page }} de {{ inventario.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="inventario.meta.current_page >= inventario.meta.last_page"
          @click="inventario.fetchList(inventario.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <!-- Ajuste manual -->
      <Dialog
        :open="articuloAAjustar !== null"
        @update:open="(v) => !v && (articuloAAjustar = null)"
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Ajustar existencia</DialogTitle>
            <DialogDescription>
              {{ articuloAAjustar?.nombre }} ({{ articuloAAjustar?.modelo }}). Captura la cantidad
              <strong>final</strong> que queda, no la diferencia. El ajuste deja el faltante
              pendiente en cero.
            </DialogDescription>
          </DialogHeader>

          <div class="space-y-3">
            <div class="space-y-1.5">
              <Label for="cantidad-ajuste">Cantidad final</Label>
              <Input
                id="cantidad-ajuste"
                :model-value="cantidadAjuste ?? undefined"
                type="number"
                min="0"
                @update:model-value="(v) => (cantidadAjuste = v === '' ? null : Number(v))"
              />
            </div>
            <div class="space-y-1.5">
              <Label>Motivo</Label>
              <Select v-model="motivoAjuste">
                <SelectTrigger class="w-full">
                  <SelectValue placeholder="Selecciona un motivo" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem
                    v-for="motivo in MOTIVOS_MANUALES"
                    :key="motivo.id"
                    :value="motivo.id"
                  >
                    {{ motivo.texto }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div class="space-y-1.5">
              <Label for="nota-ajuste">Nota (opcional)</Label>
              <Input id="nota-ajuste" v-model="notaAjuste" maxlength="500" />
            </div>
            <Alert v-if="errorAjuste" variant="destructive">
              <AlertDescription>{{ errorAjuste }}</AlertDescription>
            </Alert>
          </div>

          <DialogFooter>
            <Button variant="outline" @click="articuloAAjustar = null">Cancelar</Button>
            <Button :disabled="guardandoAjuste || cantidadAjuste === null" @click="confirmarAjuste">
              {{ guardandoAjuste ? 'Guardando...' : 'Guardar ajuste' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Umbrales -->
      <Dialog
        :open="articuloDeUmbrales !== null"
        @update:open="(v) => !v && (articuloDeUmbrales = null)"
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Mínimo y máximo</DialogTitle>
            <DialogDescription>
              {{ articuloDeUmbrales?.nombre }}. El mínimo es cuándo avisarte; el máximo, hasta dónde
              rellenar. Un mínimo en cero significa que no quieres avisos de este artículo.
            </DialogDescription>
          </DialogHeader>

          <div class="space-y-3">
            <div class="space-y-1.5">
              <Label for="minimo">Mínimo</Label>
              <Input id="minimo" v-model.number="minimo" type="number" min="0" />
            </div>
            <div class="space-y-1.5">
              <Label for="maximo">Máximo (opcional)</Label>
              <Input
                id="maximo"
                :model-value="maximo ?? undefined"
                type="number"
                min="0"
                @update:model-value="(v) => (maximo = v === '' ? null : Number(v))"
              />
            </div>
            <Alert v-if="errorUmbrales" variant="destructive">
              <AlertDescription>{{ errorUmbrales }}</AlertDescription>
            </Alert>
          </div>

          <DialogFooter>
            <Button variant="outline" @click="articuloDeUmbrales = null">Cancelar</Button>
            <Button :disabled="guardandoUmbrales" @click="confirmarUmbrales">
              {{ guardandoUmbrales ? 'Guardando...' : 'Guardar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Generación de órdenes -->
      <Dialog v-model:open="mostrarGenerar">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Generar órdenes de compra</DialogTitle>
            <DialogDescription>
              Se creará una orden en <strong>borrador</strong> por proveedor con los artículos por
              pedir y sus cantidades sugeridas. No se envía nada al proveedor.
            </DialogDescription>
          </DialogHeader>

          <div class="space-y-3">
            <p v-if="resumenPorProveedor.length === 0" class="text-muted-foreground text-sm">
              En esta página no hay artículos por pedir, pero sí en el resto del inventario. Se
              generarán las órdenes de todos ellos.
            </p>
            <ul v-else class="space-y-1 text-sm">
              <li
                v-for="fila in resumenPorProveedor"
                :key="fila.proveedor"
                class="flex justify-between"
              >
                <span>{{ fila.proveedor }}</span>
                <span class="text-muted-foreground tabular-nums">
                  {{ fila.articulos }} artículos · {{ fila.unidades }} piezas
                </span>
              </li>
            </ul>

            <Alert v-if="omitidos.length > 0">
              <AlertDescription>
                No se incluyeron {{ omitidos.length }} artículo(s) porque su catálogo o proveedor
                está eliminado: {{ omitidos.map((o) => o.modelo).join(', ') }}.
              </AlertDescription>
            </Alert>
            <Alert v-if="errorGenerar" variant="destructive">
              <AlertDescription>{{ errorGenerar }}</AlertDescription>
            </Alert>
          </div>

          <DialogFooter>
            <Button variant="outline" @click="mostrarGenerar = false">Cancelar</Button>
            <Button :disabled="generando" @click="confirmarGenerar">
              {{ generando ? 'Generando...' : 'Generar' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Quitar de existencias -->
      <Dialog :open="articuloAQuitar !== null" @update:open="(v) => !v && (articuloAQuitar = null)">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Quitar de existencias</DialogTitle>
            <DialogDescription>
              {{ articuloAQuitar?.nombre }} ({{ articuloAQuitar?.modelo }}) dejará de aparecer en
              Existencias. Su historial de movimientos se conserva, y si lo vuelves a marcar
              después recupera los mismos números.
              <strong v-if="articuloAQuitar && articuloAQuitar.existencia > 0" class="text-destructive block mt-2">
                Todavía tiene {{ articuloAQuitar.existencia }} pieza(s) en existencia.
              </strong>
            </DialogDescription>
          </DialogHeader>

          <Alert v-if="errorQuitar" variant="destructive">
            <AlertDescription>{{ errorQuitar }}</AlertDescription>
          </Alert>

          <DialogFooter>
            <Button variant="outline" @click="articuloAQuitar = null">Cancelar</Button>
            <Button variant="destructive" :disabled="quitando" @click="confirmarQuitar">
              {{ quitando ? 'Quitando...' : 'Quitar de existencias' }}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <!-- Auditoría -->
      <Dialog v-model:open="mostrarAuditoria">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Auditoría de existencias</DialogTitle>
            <DialogDescription>
              Reconstruye cada artículo desde su historial y compara. Solo reporta: los descuadres
              se corrigen con un ajuste manual, que queda registrado.
            </DialogDescription>
          </DialogHeader>

          <div class="space-y-3">
            <p v-if="auditando" class="text-muted-foreground text-sm">Revisando...</p>
            <p v-else-if="descuadres && descuadres.length === 0" class="text-sm">
              Todo cuadra: cada existencia coincide con su historial.
            </p>
            <ul v-else-if="descuadres" class="space-y-2 text-sm">
              <li v-for="descuadre in descuadres" :key="descuadre.articulo_id">
                <span class="font-medium">{{ descuadre.nombre }} ({{ descuadre.modelo }})</span>
                <span class="text-muted-foreground block">
                  Guardado {{ descuadre.existencia_guardada }} · historial
                  {{ descuadre.existencia_calculada }}
                </span>
              </li>
            </ul>
            <Alert v-if="errorAuditoria" variant="destructive">
              <AlertDescription>{{ errorAuditoria }}</AlertDescription>
            </Alert>
          </div>

          <DialogFooter>
            <Button variant="outline" @click="mostrarAuditoria = false">Cerrar</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
