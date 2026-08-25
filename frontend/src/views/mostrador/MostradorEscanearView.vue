<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { documentoDeCodigoEtiqueta, vibrarLectura } from '../../lib/lectorQr'
import { usePedidosStore } from '../../stores/pedidos'
import { useCotizacionesStore } from '../../stores/cotizaciones'
import AppLayout from '../../layouts/AppLayout.vue'
import EscanerQr from '../../components/EscanerQr.vue'

/**
 * El escáner de etiquetas (ver 029-pwa-mostrador.md), extendido en 038 para reconocer también las
 * etiquetas de Cotización.
 *
 * Para entregar sin este escáner hay que salir a la app de cámara del celular, que abre la dirección
 * de la etiqueta en el navegador. Esto elimina ese rodeo: la cámara se abre dentro de la aplicación
 * y, al reconocer una etiqueta del sistema, navega directo al trabajo sin recargar nada.
 *
 * **A dónde lleva el escaneo cambia según si el trabajo ya tiene ficha de Producción** (ver
 * 039-qr-conductor-produccion.md): con Orden de Trabajo asociada, navega directo a esa ficha; sin
 * ella, sigue llevando a la vista de entrega del documento, igual que hasta ahora.
 */

const router = useRouter()
const pedidos = usePedidosStore()
const cotizaciones = useCotizacionesStore()

const aviso = ref<string | null>(null)

async function onCodigo(codigo: string) {
  const documento = documentoDeCodigoEtiqueta(codigo, window.location.origin)

  // Nunca se abre una dirección de afuera: un QR pegado en cualquier caja podría llevar a donde
  // sea, y el escáner de un punto de venta no es un navegador.
  if (documento === null) {
    aviso.value = 'Ese código no es de una etiqueta del sistema.'
    return
  }

  aviso.value = null
  vibrarLectura()

  try {
    const ordenTrabajoId =
      documento.tipo === 'pedido'
        ? (await pedidos.fetchOne(documento.id)).orden_trabajo_id
        : (await cotizaciones.fetchOne(documento.id)).orden_trabajo_id

    if (ordenTrabajoId) {
      await router.push({ name: 'produccion-detalle', params: { id: ordenTrabajoId } })
      return
    }
  } catch {
    // Si la consulta falla, se sigue por el camino de siempre: la vista de entrega también
    // carga el documento y muestra el error ahí.
  }

  const nombreRuta = documento.tipo === 'pedido' ? 'pedidos-entregar' : 'cotizaciones-entregar'
  await router.push({ name: nombreRuta, params: { id: documento.id } })
}
</script>

<template>
  <AppLayout mostrador>
    <div class="mx-auto max-w-md space-y-4">
      <h1 class="font-heading text-foreground text-xl font-semibold">Escanear etiquetas</h1>

      <div class="h-[65svh]">
        <EscanerQr class="h-full" :aviso="aviso" @codigo="onCodigo" />
      </div>

      <p class="text-muted-foreground text-center text-sm">
        Apunta a la etiqueta del paquete. Se lee sola, no hay que apretar nada.
      </p>
    </div>
  </AppLayout>
</template>
