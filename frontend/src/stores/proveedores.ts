import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

export interface Proveedor {
  id: number
  nombre_comercial: string
  nombre_contacto: string | null
  correo: string | null
  telefono: string | null
  rfc: string | null
  tiene_ordenes_activas: boolean
  created_at: string
  updated_at: string
}

export interface ProveedorPayload {
  nombre_comercial: string
  nombre_contacto: string | null
  correo: string | null
  telefono: string | null
  rfc: string | null
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

export const useProveedoresStore = defineStore('proveedores', {
  state: () => ({
    items: [] as Proveedor[],
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
        const { data } = await http.get('/proveedores', {
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

    async fetchOne(id: number): Promise<Proveedor> {
      const { data } = await http.get(`/proveedores/${id}`)
      return data.data
    },

    async create(payload: ProveedorPayload): Promise<Proveedor> {
      const { data } = await http.post('/proveedores', payload)
      return data.data
    },

    async update(id: number, payload: ProveedorPayload): Promise<Proveedor> {
      const { data } = await http.put(`/proveedores/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/proveedores/${id}`)
      this.items = this.items.filter((proveedor) => proveedor.id !== id)
    },
  },
})
