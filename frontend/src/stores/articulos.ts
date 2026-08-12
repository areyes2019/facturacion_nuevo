import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

export interface Articulo {
  id: number
  catalogo_id: number
  catalogo_nombre: string | null
  proveedor_id: number | null
  proveedor_nombre_comercial: string | null
  nombre: string
  modelo: string
  clave_prod_serv: string
  clave_unidad: string
  objeto_imp: string
  precio_proveedor: number
  utilidad_porcentaje: number | null
  utilidad_porcentaje_efectivo: number | null
  tamano_goma: string | null
  costo_goma: number
  costo_con_descuento: number
  costo_total: number
  precio_unitario_sin_iva: number
  precio_unitario_con_iva: number
  utilidad: number
  created_at: string
  updated_at: string
}

export interface ArticuloPayload {
  catalogo_id: number | null
  nombre: string
  modelo: string
  clave_prod_serv: string | null
  clave_unidad: string | null
  objeto_imp: string | null
  precio_proveedor: number | null
  utilidad_porcentaje: number | null
  tamano_goma: string | null
}

export interface ImportarCsvReporte {
  importados: number
  errores: { fila: number; motivo: string }[]
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

/**
 * Columnas numéricas ordenables del listado (ver 011-precio-proveedor-utilidad.md). El costo que se
 * muestra y por el que se ordena es el total, aparato + goma (ver 014-costo-elaboracion-goma.md).
 */
export type ArticuloSort = 'costo_total' | 'precio_unitario_sin_iva' | 'utilidad'
export type SortDirection = 'asc' | 'desc'

export const useArticulosStore = defineStore('articulos', {
  state: () => ({
    items: [] as Articulo[],
    meta: null as PaginationMeta | null,
    search: '',
    sort: null as ArticuloSort | null,
    direction: 'asc' as SortDirection,
    loading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(page = 1) {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/articulos', {
          params: {
            page,
            search: this.search || undefined,
            sort: this.sort ?? undefined,
            direction: this.sort ? this.direction : undefined,
          },
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

    async fetchOne(id: number): Promise<Articulo> {
      const { data } = await http.get(`/articulos/${id}`)
      return data.data
    },

    async create(payload: ArticuloPayload): Promise<Articulo> {
      const { data } = await http.post('/articulos', payload)
      return data.data
    },

    async update(id: number, payload: ArticuloPayload): Promise<Articulo> {
      const { data } = await http.put(`/articulos/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/articulos/${id}`)
      this.items = this.items.filter((articulo) => articulo.id !== id)
    },

    async importarCsv(catalogoId: number, archivo: File): Promise<ImportarCsvReporte> {
      const formData = new FormData()
      formData.append('archivo', archivo)

      const { data } = await http.post(
        `/catalogos-proveedor/${catalogoId}/articulos/importar-csv`,
        formData,
      )
      return data
    },

    /** Alterna la ordenación de una columna: asc -> desc -> sin ordenar. */
    async toggleSort(columna: ArticuloSort) {
      if (this.sort !== columna) {
        this.sort = columna
        this.direction = 'asc'
      } else if (this.direction === 'asc') {
        this.direction = 'desc'
      } else {
        this.sort = null
        this.direction = 'asc'
      }

      await this.fetchList(1)
    },

    async exportarCsv() {
      const { data } = await http.get('/articulos/exportar-csv', {
        params: {
          search: this.search || undefined,
          sort: this.sort ?? undefined,
          direction: this.sort ? this.direction : undefined,
        },
        responseType: 'blob',
      })

      const url = URL.createObjectURL(new Blob([data], { type: 'text/csv' }))
      const enlace = document.createElement('a')
      enlace.href = url
      enlace.download = 'articulos.csv'
      enlace.click()
      URL.revokeObjectURL(url)
    },
  },
})
