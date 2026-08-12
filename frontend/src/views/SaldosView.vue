<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useCuentasStore } from '../stores/cuentas'
import AppLayout from '../layouts/AppLayout.vue'
import { Card, CardContent } from '../components/ui/card'
import { Alert, AlertDescription } from '../components/ui/alert'
import { Badge } from '../components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from '../components/ui/table'

const cuentas = useCuentasStore()

onMounted(() => cuentas.fetchSaldos())

function moneda(valor: number) {
  return `$${valor.toFixed(2)}`
}
</script>

<template>
  <AppLayout>
    <div class="space-y-4">
      <div>
        <h1 class="font-heading text-foreground text-xl font-semibold">Saldos</h1>
        <p class="text-muted-foreground text-sm">
          Saldo actual de todas tus cuentas, activas e inactivas.
        </p>
      </div>

      <Alert v-if="cuentas.error" variant="destructive">
        <AlertDescription>{{ cuentas.error }}</AlertDescription>
      </Alert>

      <Card>
        <CardContent class="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Cuenta</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead class="text-right">Saldo actual</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-if="!cuentas.loading && cuentas.saldos.length === 0">
                <TableCell colspan="4" class="text-muted-foreground py-10 text-center">
                  No hay cuentas registradas todavía.
                </TableCell>
              </TableRow>
              <TableRow v-for="cuenta in cuentas.saldos" :key="cuenta.id">
                <TableCell>
                  <!-- Cada renglón lleva al listado de movimientos ya filtrado por esa cuenta. -->
                  <RouterLink
                    :to="{ name: 'movimientos', query: { cuenta_id: String(cuenta.id) } }"
                    class="text-primary hover:underline"
                  >
                    {{ cuenta.nombre }}
                  </RouterLink>
                </TableCell>
                <TableCell>{{ cuenta.tipo_texto }}</TableCell>
                <TableCell>
                  <Badge :variant="cuenta.activa ? 'success' : 'secondary'">
                    {{ cuenta.activa ? 'Activa' : 'Inactiva' }}
                  </Badge>
                </TableCell>
                <TableCell class="text-right font-medium">
                  {{ moneda(cuenta.saldo_actual) }}
                </TableCell>
              </TableRow>
            </TableBody>
            <TableFooter v-if="cuentas.saldos.length > 0">
              <TableRow>
                <TableCell colspan="3" class="font-semibold">Total global</TableCell>
                <TableCell class="text-right text-base font-semibold">
                  {{ moneda(cuentas.totalGlobal) }}
                </TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
