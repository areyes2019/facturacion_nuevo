import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

export interface Catalogo {
  id: number
  proveedor_id: number
  proveedor_nombre_comercial: string | null
  nombre: string
  descuento: number
  utilidad_porcentaje: number
  utilidad_distribuidor_porcentaje: number
  /** Cuántos artículos se lleva por delante el borrado del catálogo (ver 021). */
  articulos_count: number
  created_at: string
  updated_at: string
}

/**
 * Una fila de la vista previa de precios: lo que tendría el artículo con los valores propuestos,
 * sin haber guardado nada (ver 021-mantenimiento-articulos-catalogos.md y
 * 033-precio-distribuidor.md).
 */
export interface ImpactoArticulo {
  id: number
  nombre: string
  modelo: string
  precio_proveedor: number
  costo_con_descuento: number
  costo_total: number
  precio_unitario_sin_iva: number
  precio_distribuidor_sin_iva: number
}

export interface ImpactoPayload {
  descuento?: number | null
  utilidad_porcentaje?: number | null
  utilidad_distribuidor_porcentaje?: number | null
  aumento_porcentaje?: number | null
}

export interface CatalogoPayload {
  proveedor_id?: number | null
  nombre: string
  descuento: number | null
  utilidad_porcentaje: number | null
  utilidad_distribuidor_porcentaje: number | null
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

export const useCatalogosStore = defineStore('catalogos', {
  state: () => ({
    items: [] as Catalogo[],
    meta: null as PaginationMeta | null,
    search: '',
    loading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(page = 1) {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/catalogos-proveedor', {
          params: { page, search: this.search || undefined },
        })
        this.items = data.data
        this.meta = data.meta
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      } finally {
        this.loading = false
      }
    },

    async fetchOne(id: number): Promise<Catalogo> {
      const { data } = await http.get(`/catalogos-proveedor/${id}`)
      return data.data
    },

    async create(payload: CatalogoPayload): Promise<Catalogo> {
      const { data } = await http.post('/catalogos-proveedor', payload)
      return data.data
    },

    async update(id: number, payload: CatalogoPayload): Promise<Catalogo> {
      const { data } = await http.put(`/catalogos-proveedor/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/catalogos-proveedor/${id}`)
      this.items = this.items.filter((catalogo) => catalogo.id !== id)
    },

    /**
     * Vista previa de precios, sin persistir nada. Los tres valores son opcionales y el backend cae
     * a lo que el catálogo tiene guardado (ver 021-mantenimiento-articulos-catalogos.md).
     */
    async impactoPrecios(id: number, payload: ImpactoPayload): Promise<ImpactoArticulo[]> {
      const { data } = await http.post(`/catalogos-proveedor/${id}/impacto-precios`, payload)
      return data.articulos
    },

    /** Aplica el aumento porcentual del costo a todos los artículos del catálogo. */
    async aumentarCostos(id: number, aumentoPorcentaje: number): Promise<number> {
      const { data } = await http.post(`/catalogos-proveedor/${id}/aumentar-costos`, {
        aumento_porcentaje: aumentoPorcentaje,
      })
      return data.actualizados
    },
  },
})
