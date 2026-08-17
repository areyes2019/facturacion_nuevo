<script setup lang="ts">
import AppLayout from '../../layouts/AppLayout.vue'
import ListaDocumentosMostrador, {
  type EtiquetaEstado,
} from '../../components/mostrador/ListaDocumentosMostrador.vue'

/**
 * La lista de facturas del mostrador (ver 031-mostrador-consulta.md), la misma pantalla que la de
 * cotizaciones con otra lista y otros estados.
 */
const ESTADOS: Record<string, EtiquetaEstado> = {
  borrador: { etiqueta: 'Borrador', variant: 'secondary' },
  pendiente: { etiqueta: 'Pendiente', variant: 'warning' },
  timbrada: { etiqueta: 'Timbrada', variant: 'success' },
  cancelada: { etiqueta: 'Cancelada', variant: 'destructive' },
}
</script>

<template>
  <AppLayout mostrador barra>
    <div class="mx-auto max-w-md space-y-5">
      <h1 class="font-heading text-foreground text-xl font-semibold">Facturas</h1>

      <ListaDocumentosMostrador
        url="/facturas"
        clave="mostrador-facturas"
        ruta-detalle="mostrador-factura-ver"
        placeholder="Buscar por folio, cliente o folio fiscal..."
        :estados="ESTADOS"
        vacio="No hay facturas de los últimos 30 días. Busca por folio, cliente o folio fiscal para ver más atrás."
      />
    </div>
  </AppLayout>
</template>
