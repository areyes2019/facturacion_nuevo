<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { usePedidosStore, type Pedido } from '../stores/pedidos'
import { extractErrorMessage } from '../lib/errors'

/**
 * Etiqueta adhesiva de 5 × 2.5 cm (ver 027-venta-mostrador-ticket.md).
 *
 * Se imprime **desde el navegador**, sin generar archivo, y sin el layout de la aplicación: lo que
 * sale por la impresora son 50 × 25 mm y nada más.
 *
 * Lleva nombre, teléfono, no. de ticket, el saldo y el QR. **El QR va a la izquierda en un cuadro
 * de 20 mm** y el texto en los ~28 mm restantes: un código necesita ser cuadrado y con 25 mm de
 * alto no puede crecer más sin comerse los márgenes, así que se aprovecha que la etiqueta es más
 * ancha que alta.
 *
 * El saldo se imprime porque al ver la caja hay que saber si falta cobrar sin abrir el sistema. La
 * primera versión lo prohibía —"pasa por manos que no tienen por qué ver lo que se cobró"— y ese
 * argumento no aplica a un taller de una persona: no hay manos ajenas por las que pase.
 */
const route = useRoute()
const pedidos = usePedidosStore()

const pedido = ref<Pedido | null>(null)
const error = ref<string | null>(null)

const saldo = computed(() =>
  pedido.value === null || pedido.value.saldo_pendiente <= 0
    ? 'PAGADO'
    : `SALDO: $${pedido.value.saldo_pendiente.toFixed(2)}`,
)

function imprimir() {
  window.print()
}

onMounted(async () => {
  try {
    pedido.value = await pedidos.fetchOne(Number(route.params.id))

    // Se espera al repintado para que el diálogo de impresión no capture la etiqueta a medio
    // dibujar, con el QR todavía sin aparecer.
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

    <div v-else-if="pedido" class="etiqueta">
      <img
        v-if="pedido.qr_entrega"
        class="qr"
        :src="pedido.qr_entrega"
        alt="Código QR del pedido"
      />
      <div class="datos">
        <p class="nombre">{{ pedido.cliente_nombre }}</p>
        <p class="telefono">{{ pedido.cliente_telefono }}</p>
        <p class="ticket">No. {{ pedido.numero_ticket }}</p>
        <p class="saldo">{{ saldo }}</p>
      </div>
    </div>

    <p v-if="pedido" class="pie-pantalla">
      Si no se abrió el diálogo de impresión,
      <button type="button" @click="imprimir">imprime aquí</button>.
    </p>
  </div>
</template>

<style scoped>
/*
 * Medidas en milímetros y no en píxeles: la etiqueta tiene un tamaño físico exacto y lo que se
 * imprime debe medir eso en el papel, sin depender de la densidad de la pantalla.
 */
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
  /* Solo en pantalla: sirve para ver los límites del adhesivo antes de gastar etiquetas. */
  border: 1px dashed #bbb;
  font-family: Arial, Helvetica, sans-serif;
}

.datos {
  flex: 1;
  min-width: 0;
}

/*
 * Los cuatro renglones se recortan con puntos suspensivos antes que partirse: un renglón que se
 * parte empuja al saldo fuera de los 25 mm de alto.
 */
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

/* El único dato que obliga a actuar, y por eso el último: es donde el ojo lo busca. */
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
