<script setup lang="ts">
import AppLayout from '../../layouts/AppLayout.vue'
import ListaDocumentosMostrador, {
  type EtiquetaEstado,
} from '../../components/mostrador/ListaDocumentosMostrador.vue'

/**
 * La lista de cotizaciones del mostrador (ver 031-mostrador-consulta.md).
 *
 * Los mismos colores de estado que la tabla del escritorio, con la etiqueta escrita en vez del
 * valor crudo: "producto_entregado" en una tarjeta de celular no es una palabra.
 */
const ESTADOS: Record<string, EtiquetaEstado> = {
  borrador: { etiqueta: 'Borrador', variant: 'secondary' },
  enviada: { etiqueta: 'Enviada', variant: 'warning' },
  pagada: { etiqueta: 'Pagada', variant: 'success' },
  producto_entregado: { etiqueta: 'Entregada', variant: 'default' },
}
</script>

<template>
  <AppLayout mostrador barra>
    <div class="mx-auto max-w-md space-y-5">
      <h1 class="font-heading text-foreground text-xl font-semibold">Cotizaciones</h1>

      <ListaDocumentosMostrador
        url="/cotizaciones"
        clave="mostrador-cotizaciones"
        ruta-detalle="mostrador-cotizacion-ver"
        placeholder="Buscar por folio o cliente..."
        :estados="ESTADOS"
        vacio="No hay cotizaciones de los últimos 30 días. Busca por folio o cliente para ver más atrás."
      />
    </div>
  </AppLayout>
</template>
