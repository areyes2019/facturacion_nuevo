import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

/**
 * Un renglón de la pantalla de Existencias (ver 017-inventario.md). Es la vista operativa del
 * artículo —cuánto hay, cuánto falta, cuándo reponer y cuánto dinero representa—, distinta de sus
 * datos maestros, que siguen viviendo en el store de artículos.
 */
export interface RenglonInventario {
  id: number
  nombre: string
  modelo: string
  catalogo_id: number
  catalogo_nombre: string | null
  proveedor_id: number | null
  proveedor_nombre_comercial: string | null
  existencia: number
  faltante_pendiente: number
  minimo: number
  maximo: number | null
  costo_total: number
  precio_unitario_sin_iva: number
  utilidad: number
  dinero_invertido: number
  beneficio_potencial: number
  por_pedir: boolean
  cantidad_sugerida: number
}

export interface TotalesInventario {
  unidades: number
  dinero_invertido: number
  beneficio_potencial: number
  /** Dinero invertido + beneficio potencial: el valor de venta total de la bodega, sin IVA. */
  total_general: number
  articulos_por_pedir: number
}

export interface MovimientoInventario {
  id: number
  articulo_id: number
  tipo: 'entrada' | 'salida' | 'ajuste'
  tipo_texto: string
  motivo: string
  motivo_texto: string
  cantidad: number
  existencia_resultante: number
  faltante_resultante: number
  nota: string | null
  es_automatico: boolean
  documento: { tipo: 'orden_compra' | 'factura' | 'cotizacion'; id: number; folio: string } | null
  created_at: string
}

export interface ArticuloOmitido {
  id: number
  nombre: string
  modelo: string
  motivo: string
}

export interface Descuadre {
  articulo_id: number
  nombre: string
  modelo: string
  existencia_guardada: number
  existencia_calculada: number
  faltante_guardado: number
  faltante_calculado: number
}

/** Motivos que el usuario puede elegir en un ajuste; los automáticos los escribe el backend. */
export const MOTIVOS_MANUALES = [
  { id: 'conteo_fisico', texto: 'Conteo físico' },
  { id: 'merma', texto: 'Merma o daño' },
  { id: 'devolucion', texto: 'Devolución' },
  { id: 'entrada_inicial', texto: 'Entrada inicial' },
  { id: 'otro', texto: 'Otro' },
] as const

export type OrdenInventario =
  'nombre' | 'modelo' | 'existencia' | 'faltante' | 'invertido' | 'beneficio'
export type DireccionOrden = 'asc' | 'desc'

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

export const useInventarioStore = defineStore('inventario', {
  state: () => ({
    items: [] as RenglonInventario[],
    meta: null as PaginationMeta | null,
    /**
     * Los cuatro totales del conjunto filtrado completo, no de la página visible: si se sumaran
     * los quince renglones a la vista, el dinero invertido cambiaría al pasar de página.
     */
    totales: null as TotalesInventario | null,
    q: '',
    proveedorId: null as number | null,
    soloPorPedir: false,
    orden: null as OrdenInventario | null,
    direccion: 'asc' as DireccionOrden,
    loading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(page = 1) {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/inventario', {
          params: {
            page,
            q: this.q || undefined,
            proveedor: this.proveedorId ?? undefined,
            por_pedir: this.soloPorPedir ? 1 : undefined,
            orden: this.orden ?? undefined,
            dir: this.orden ? this.direccion : undefined,
          },
        })
        this.items = data.data
        this.meta = data.meta
        this.totales = data.meta?.totales ?? null
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      } finally {
        this.loading = false
      }
    },

    /** Alterna la ordenación de una columna: asc -> desc -> sin ordenar. */
    async toggleOrden(columna: OrdenInventario) {
      if (this.orden !== columna) {
        this.orden = columna
        this.direccion = 'asc'
      } else if (this.direccion === 'asc') {
        this.direccion = 'desc'
      } else {
        this.orden = null
        this.direccion = 'asc'
      }

      await this.fetchList(1)
    },

    /** `cantidad` es la cantidad final que queda, no la diferencia. */
    async ajustar(articuloId: number, cantidad: number, motivo: string, nota: string | null) {
      const { data } = await http.post(`/inventario/${articuloId}/ajuste`, {
        cantidad,
        motivo,
        nota: nota || undefined,
      })
      return data.data as RenglonInventario
    },

    async guardarParametros(articuloId: number, minimo: number, maximo: number | null) {
      const { data } = await http.put(`/inventario/${articuloId}/parametros`, { minimo, maximo })
      return data.data as RenglonInventario
    },

    /** Quita el artículo de existencias (borrado lógico). Su historial y sus números se conservan. */
    async quitar(articuloId: number) {
      await http.delete(`/inventario/${articuloId}`)
    },

    async fetchMovimientos(articuloId: number, page = 1) {
      const { data } = await http.get(`/inventario/${articuloId}/movimientos`, { params: { page } })
      return { items: data.data as MovimientoInventario[], meta: data.meta as PaginationMeta }
    },

    async generarOrdenesCompra() {
      const { data } = await http.post('/inventario/generar-ordenes-compra')
      return {
        ordenes: data.data as { id: number; folio_formateado: string }[],
        omitidos: data.omitidos as ArticuloOmitido[],
      }
    },

    async auditar() {
      const { data } = await http.get('/inventario/auditoria')
      return data.data as Descuadre[]
    },
  },
})
