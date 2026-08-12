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
  created_at: string
  updated_at: string
}

export interface CatalogoPayload {
  proveedor_id?: number | null
  nombre: string
  descuento: number | null
  utilidad_porcentaje: number | null
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
  },
})
