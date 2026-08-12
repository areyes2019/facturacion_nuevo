<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { PlusIcon, PencilIcon, TrashIcon, PowerIcon } from '@heroicons/vue/24/outline'
import { useCuentasStore, type Cuenta } from '../stores/cuentas'
import { extractErrorMessage } from '../lib/errors'
import AppLayout from '../layouts/AppLayout.vue'
import { Button } from '../components/ui/button'
import { Card, CardContent } from '../components/ui/card'
import { Input } from '../components/ui/input'
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

const cuentas = useCuentasStore()

const cuentaAEliminar = ref<Cuenta | null>(null)
const eliminando = ref(false)
const errorEliminar = ref<string | null>(null)
// Una cuenta con movimientos no se puede borrar (409); la alternativa es desactivarla, y se ofrece
// dentro del propio diálogo (ver 010-tesoreria.md).
const ofrecerDesactivar = ref(false)

onMounted(() => cuentas.fetchList())

let buscarTimeout: ReturnType<typeof setTimeout>
function onBuscar() {
  clearTimeout(buscarTimeout)
  buscarTimeout = setTimeout(() => cuentas.fetchList(1), 300)
}

function irAPagina(pagina: number) {
  cuentas.fetchList(pagina)
}

function abrirEliminar(cuenta: Cuenta) {
  cuentaAEliminar.value = cuenta
  errorEliminar.value = null
  ofrecerDesactivar.value = false
}

function cerrarEliminar() {
  cuentaAEliminar.value = null
  errorEliminar.value = null
  ofrecerDesactivar.value = false
}

async function confirmarEliminar() {
  if (!cuentaAEliminar.value) return

  eliminando.value = true
  errorEliminar.value = null
  try {
    await cuentas.remove(cuentaAEliminar.value.id)
    cerrarEliminar()
  } catch (err) {
    errorEliminar.value = extractErrorMessage(err)
    ofrecerDesactivar.value = cuentaAEliminar.value.activa
  } finally {
    eliminando.value = false
  }
}

async function alternarActiva(cuenta: Cuenta) {
  await cuentas.update(cuenta.id, {
    nombre: cuenta.nombre,
    tipo: cuenta.tipo,
    activa: !cuenta.activa,
  })
  await cuentas.fetchList(cuentas.meta?.current_page ?? 1)
}

async function desactivarDesdeDialogo() {
  if (!cuentaAEliminar.value) return

  eliminando.value = true
  try {
    await alternarActiva(cuentaAEliminar.value)
    cerrarEliminar()
  } catch (err) {
    errorEliminar.value = extractErrorMessage(err)
  } finally {
    eliminando.value = false
  }
}

function moneda(valor: number) {
  return `$${valor.toFixed(2)}`
}
</script>

<template>
  <AppLayout>
    <div class="space-y-4">
      <div class="flex items-center justify-between gap-4">
        <h1 class="font-heading text-foreground text-xl font-semibold">Cuentas</h1>
        <Button as-child>
          <RouterLink :to="{ name: 'cuentas-crear' }">
            <PlusIcon class="size-4" />
            Nueva cuenta
          </RouterLink>
        </Button>
      </div>

      <div class="flex flex-wrap gap-2">
        <Input
          v-model="cuentas.search"
          placeholder="Buscar por nombre..."
          class="max-w-sm"
          @update:model-value="onBuscar"
        />
        <select
          v-model="cuentas.filtroActiva"
          class="border-input h-9 rounded-md border bg-transparent px-2 text-sm"
          @change="cuentas.fetchList(1)"
        >
          <option value="">Todas</option>
          <option value="true">Activas</option>
          <option value="false">Inactivas</option>
        </select>
      </div>

      <Alert v-if="cuentas.error" variant="destructive">
        <AlertDescription>{{ cuentas.error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Nombre</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead class="text-right">Saldo inicial</TableHead>
                <TableHead class="text-right">Saldo actual</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead class="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!cuentas.loading && cuentas.items.length === 0">
                <TableCell colspan="6" class="text-muted-foreground py-10 text-center">
                  No hay cuentas registradas todavía.
                </TableCell>
              </TableRow>
              <TableRow v-for="cuenta in cuentas.items" :key="cuenta.id">
                <TableCell>{{ cuenta.nombre }}</TableCell>
                <TableCell>{{ cuenta.tipo_texto }}</TableCell>
                <TableCell class="text-right">{{ moneda(cuenta.saldo_inicial) }}</TableCell>
                <TableCell class="text-right font-medium">
                  {{ moneda(cuenta.saldo_actual) }}
                </TableCell>
                <TableCell>
                  <Badge :variant="cuenta.activa ? 'success' : 'secondary'">
                    {{ cuenta.activa ? 'Activa' : 'Inactiva' }}
                  </Badge>
                </TableCell>
                <TableCell class="flex justify-end gap-2 text-right">
                  <Button
                    variant="outline"
                    size="icon-sm"
                    :title="cuenta.activa ? 'Desactivar' : 'Activar'"
                    @click="alternarActiva(cuenta)"
                  >
                    <PowerIcon class="size-4" />
                    <span class="sr-only">{{ cuenta.activa ? 'Desactivar' : 'Activar' }}</span>
                  </Button>
                  <Button as-child variant="outline" size="icon-sm">
                    <RouterLink :to="{ name: 'cuentas-editar', params: { id: cuenta.id } }">
                      <PencilIcon class="size-4" />
                      <span class="sr-only">Editar</span>
                    </RouterLink>
                  </Button>
                  <Button variant="outline" size="icon-sm" @click="abrirEliminar(cuenta)">
                    <TrashIcon class="size-4" />
                    <span class="sr-only">Eliminar</span>
                  </Button>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <div v-if="cuentas.meta && cuentas.meta.last_page > 1" class="flex justify-center gap-2">
        <Button
          variant="outline"
          size="sm"
          :disabled="cuentas.meta.current_page <= 1"
          @click="irAPagina(cuentas.meta.current_page - 1)"
        >
          Anterior
        </Button>
        <span class="text-muted-foreground self-center text-sm">
          Página {{ cuentas.meta.current_page }} de {{ cuentas.meta.last_page }}
        </span>
        <Button
          variant="outline"
          size="sm"
          :disabled="cuentas.meta.current_page >= cuentas.meta.last_page"
          @click="irAPagina(cuentas.meta.current_page + 1)"
        >
          Siguiente
        </Button>
      </div>

      <Dialog :open="cuentaAEliminar !== null" @update:open="(v) => !v && cerrarEliminar()">
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Eliminar cuenta</DialogTitle>
            <DialogDescription>
              ¿Seguro que quieres eliminar "{{ cuentaAEliminar?.nombre }}"? Esta acción no se puede
              deshacer.
            </DialogDescription>
          </DialogHeader>
          <Alert v-if="errorEliminar" variant="destructive">
            <AlertDescription>{{ errorEliminar }}</AlertDescription>
          </Alert>
          <p v-if="ofrecerDesactivar" class="text-muted-foreground text-sm">
            Puedes desactivarla en su lugar: dejará de admitir movimientos nuevos, pero conservará
            su historial y su saldo.
          </p>
          <DialogFooter>
            <Button variant="outline" :disabled="eliminando" @click="cerrarEliminar">
              Cancelar
            </Button>
            <Button v-if="ofrecerDesactivar" :disabled="eliminando" @click="desactivarDesdeDialogo">
              Desactivar cuenta
            </Button>
            <Button v-else variant="destructive" :disabled="eliminando" @click="confirmarEliminar">
              Eliminar
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  </AppLayout>
</template>
