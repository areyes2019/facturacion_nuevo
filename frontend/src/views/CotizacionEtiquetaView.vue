<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useCotizacionesStore, type Cotizacion } from '../stores/cotizaciones'
import { extractErrorMessage } from '../lib/errors'

/**
 * Etiqueta adhesiva de 5 × 2.5 cm de la cotización, calcada de `PedidoEtiquetaView.vue` (027,
 * extendido en 038-produccion-ordenes-trabajo.md). Mismas medidas, mismo QR a la izquierda.
 */
const route = useRoute()
const cotizaciones = useCotizacionesStore()

const cotizacion = ref<Cotizacion | null>(null)
const error = ref<string | null>(null)

const saldo = computed(() =>
  cotizacion.value === null || cotizacion.value.saldo_pendiente <= 0
    ? 'PAGADO'
    : `SALDO: $${cotizacion.value.saldo_pendiente.toFixed(2)}`,
)

function imprimir() {
  window.print()
}

onMounted(async () => {
  try {
    cotizacion.value = await cotizaciones.fetchOne(Number(route.params.id))

    await nextTick()
    setTimeout(imprimir, 150)
  } catch (err) {
    error.value = extractErrorMessage(err)
  }
})
</script>

<template>
  <div class="etiqueta-pagina">
    <p v-if="error" class="mensaje">{{ error }}</p>

    <div v-else-if="cotizacion" class="etiqueta">
      <img
        v-if="cotizacion.qr_entrega"
        class="qr"
        :src="cotizacion.qr_entrega"
        alt="Código QR de la cotización"
      />
      <div class="datos">
        <p class="nombre">{{ cotizacion.cliente_razon_social }}</p>
        <p class="telefono">{{ cotizacion.cliente_telefono }}</p>
        <p class="ticket">COT-{{ cotizacion.folio }}</p>
        <p class="saldo">{{ saldo }}</p>
      </div>
    </div>

    <p v-if="cotizacion" class="pie-pantalla">
      Si no se abrió el diálogo de impresión,
      <button type="button" @click="imprimir">imprime aquí</button>.
    </p>
  </div>
</template>

<style scoped>
@page {
  size: 50mm 25mm;
  margin: 0;
}

.etiqueta-pagina {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  padding: 1rem;
  background: #fff;
  color: #000;
  min-height: 100vh;
}

.etiqueta {
  box-sizing: border-box;
  width: 50mm;
  height: 25mm;
  display: flex;
  align-items: center;
  gap: 1.5mm;
  padding: 1.5mm;
  background: #fff;
  color: #000;
  border: 1px dashed #bbb;
  font-family: Arial, Helvetica, sans-serif;
}

.datos {
  flex: 1;
  min-width: 0;
}

.datos p {
  margin: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  line-height: 1.25;
}

.nombre {
  font-size: 3mm;
  font-weight: 700;
}

.telefono {
  font-size: 2.8mm;
}

.ticket {
  font-size: 3.6mm;
  font-weight: 700;
  font-family: 'Courier New', monospace;
}

.saldo {
  font-size: 3.2mm;
  font-weight: 700;
}

.qr {
  width: 20mm;
  height: 20mm;
  flex-shrink: 0;
}

.pie-pantalla {
  font-family: Arial, Helvetica, sans-serif;
  font-size: 0.8rem;
  color: #555;
}

.pie-pantalla button {
  text-decoration: underline;
  cursor: pointer;
}

.mensaje {
  font-family: Arial, Helvetica, sans-serif;
}

@media print {
  .etiqueta-pagina {
    padding: 0;
    min-height: 0;
    gap: 0;
  }

  .etiqueta {
    border: none;
  }

  .pie-pantalla {
    display: none;
  }
}
</style>
