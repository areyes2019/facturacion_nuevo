<script setup lang="ts">
import { ref } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import http from '../lib/http'
import {
  Combobox,
  ComboboxAnchor,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
  ComboboxViewport,
} from './ui/combobox'

export interface ArticuloResultado {
  id: number
  nombre: string
  modelo: string
  /** Precio de venta al cliente (factura y cotización). */
  precio_unitario_sin_iva: number
  /** Costo real: lo que le pagas al proveedor (orden de compra), ver 011. */
  costo_con_descuento: number
}

const props = withDefaults(
  defineProps<{
    /**
     * Cuando se indica, el buscador solo ofrece artículos de catálogos de ese proveedor: le
     * compras a un proveedor lo que ese proveedor vende (ver 012-ordenes-compra.md).
     */
    proveedorId?: number | null
    /** Qué precio se muestra en cada resultado: el de venta o el costo. */
    origenPrecio?: 'venta' | 'costo'
    placeholder?: string
  }>(),
  {
    proveedorId: null,
    origenPrecio: 'venta',
    placeholder: 'Buscar artículo por nombre, modelo o proveedor para agregarlo...',
  },
)

const emit = defineEmits<{ seleccionar: [articulo: ArticuloResultado] }>()

const resultados = ref<ArticuloResultado[]>([])
const buscando = ref(false)

function precioMostrado(articulo: ArticuloResultado): number {
  return props.origenPrecio === 'costo'
    ? articulo.costo_con_descuento
    : articulo.precio_unitario_sin_iva
}

const buscar = useDebounceFn(async (texto: string) => {
  if (texto.trim().length < 2) {
    resultados.value = []
    return
  }

  buscando.value = true
  try {
    const { data } = await http.get('/articulos', {
      params: { search: texto, proveedor_id: props.proveedorId ?? undefined },
    })
    resultados.value = data.data
  } finally {
    buscando.value = false
  }
}, 300)

function onSeleccionar(id: string | null) {
  const articulo = resultados.value.find((a) => a.id.toString() === id)
  if (articulo) {
    emit('seleccionar', articulo)
  }
  resultados.value = []
}
</script>

<template>
  <Combobox
    :model-value="null"
    ignore-filter
    class="w-full"
    @update:model-value="onSeleccionar($event as string | null)"
  >
    <ComboboxAnchor class="w-full">
      <ComboboxInput
        class="w-full"
        :placeholder="placeholder"
        :display-value="() => ''"
        @update:model-value="buscar($event as string)"
      />
    </ComboboxAnchor>
    <ComboboxList class="w-full">
      <ComboboxViewport>
        <ComboboxEmpty v-if="!buscando">
          {{ resultados.length === 0 ? 'Escribe al menos 2 caracteres para buscar.' : '' }}
        </ComboboxEmpty>
        <ComboboxItem v-for="item in resultados" :key="item.id" :value="item.id.toString()">
          {{ item.nombre }} ({{ item.modelo }}) — ${{ precioMostrado(item).toFixed(2) }}
        </ComboboxItem>
      </ComboboxViewport>
    </ComboboxList>
  </Combobox>
</template>
