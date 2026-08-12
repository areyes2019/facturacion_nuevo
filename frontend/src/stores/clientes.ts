import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

export interface Cliente {
  id: number
  rfc: string
  razon_social: string
  regimen_fiscal: string
  codigo_postal_fiscal: string
  tipo_persona: 'fisica' | 'moral' | null
  nombre_comercial: string | null
  correo_contacto: string | null
  telefono: string | null
  direccion_comercial: string | null
  /** Descuento permanente en porcentaje, 0 a 50 (ver 015-descuento-permanente-cliente.md). */
  descuento_permanente: number
  created_at: string
  updated_at: string
}

export interface ClientePayload {
  rfc: string
  razon_social: string
  regimen_fiscal: string
  codigo_postal_fiscal: string
  nombre_comercial: string | null
  correo_contacto: string | null
  telefono: string | null
  direccion_comercial: string | null
  descuento_permanente: number
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

export const useClientesStore = defineStore('clientes', {
  state: () => ({
    items: [] as Cliente[],
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
        const { data } = await http.get('/clientes', {
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

    async fetchOne(id: number): Promise<Cliente> {
      const { data } = await http.get(`/clientes/${id}`)
      return data.data
    },

    async create(payload: ClientePayload): Promise<Cliente> {
      const { data } = await http.post('/clientes', payload)
      return data.data
    },

    async update(id: number, payload: ClientePayload): Promise<Cliente> {
      const { data } = await http.put(`/clientes/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/clientes/${id}`)
      this.items = this.items.filter((cliente) => cliente.id !== id)
    },
  },
})
