import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

export type TipoCuenta = 'efectivo' | 'banco' | 'digital' | 'otro'

/**
 * Los 4 tipos son un enum fijo de backend, sin catálogo ni endpoint propio, así que viven también
 * embebidos aquí (mismo patrón que metodo_pago PUE/PPD en 007) — ver 010-tesoreria.md.
 */
export const TIPOS_CUENTA: { id: TipoCuenta; texto: string }[] = [
  { id: 'efectivo', texto: 'Efectivo' },
  { id: 'banco', texto: 'Banco' },
  { id: 'digital', texto: 'Digital' },
  { id: 'otro', texto: 'Otro' },
]

export interface Cuenta {
  id: number
  nombre: string
  tipo: TipoCuenta
  tipo_texto: string
  saldo_inicial: number
  saldo_actual: number
  activa: boolean
  created_at: string
  updated_at: string
}

export interface CuentaPayload {
  nombre: string
  tipo: TipoCuenta | null
  saldo_inicial?: number
  activa?: boolean
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

export const useCuentasStore = defineStore('cuentas', {
  state: () => ({
    items: [] as Cuenta[],
    meta: null as PaginationMeta | null,
    search: '',
    filtroActiva: '' as '' | 'true' | 'false',
    saldos: [] as Cuenta[],
    totalGlobal: 0,
    loading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(page = 1) {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/cuentas', {
          params: {
            page,
            search: this.search || undefined,
            activa: this.filtroActiva || undefined,
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

    /** UC-07: saldo de todas las cuentas (activas e inactivas) más el total global, sin paginar. */
    async fetchSaldos() {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/cuentas/saldos')
        this.saldos = data.data
        this.totalGlobal = data.total_global
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      } finally {
        this.loading = false
      }
    },

    async fetchOne(id: number): Promise<Cuenta> {
      const { data } = await http.get(`/cuentas/${id}`)
      return data.data
    },

    async create(payload: CuentaPayload): Promise<Cuenta> {
      const { data } = await http.post('/cuentas', payload)
      return data.data
    },

    async update(id: number, payload: CuentaPayload): Promise<Cuenta> {
      const { data } = await http.put(`/cuentas/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/cuentas/${id}`)
      this.items = this.items.filter((cuenta) => cuenta.id !== id)
    },
  },
})
