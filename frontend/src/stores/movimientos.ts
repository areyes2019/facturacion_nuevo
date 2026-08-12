import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

export type TipoMovimiento = 'ingreso' | 'egreso' | 'transferencia' | 'ajuste'

export const TIPOS_MOVIMIENTO: { id: TipoMovimiento; texto: string }[] = [
  { id: 'ingreso', texto: 'Ingreso' },
  { id: 'egreso', texto: 'Egreso' },
  { id: 'transferencia', texto: 'Transferencia' },
  { id: 'ajuste', texto: 'Ajuste' },
]

/** Documento de otro módulo que originó el movimiento; hoy solo pagos de cotización. */
export interface DocumentoOrigen {
  tipo: string
  etiqueta: string
  ruta: string
  id: number
}

export interface Movimiento {
  id: number
  cuenta_id: number
  cuenta_nombre: string | null
  tipo: TipoMovimiento
  tipo_texto: string
  monto: number
  /** Cuánto suma o resta al saldo de la cuenta: ya viene con el signo resuelto por el backend. */
  efecto_en_saldo: number
  fecha: string
  concepto: string
  es_automatico: boolean
  transferencia_id: string | null
  documento_origen: DocumentoOrigen | null
  created_at: string
}

export interface MovimientoPayload {
  tipo: Exclude<TipoMovimiento, 'transferencia'>
  cuenta_id: number | null
  monto: number | null
  fecha: string
  concepto: string
}

export interface TransferenciaPayload {
  cuenta_origen_id: number | null
  cuenta_destino_id: number | null
  monto: number | null
  fecha: string
  concepto: string
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

export const useMovimientosStore = defineStore('movimientos', {
  state: () => ({
    items: [] as Movimiento[],
    meta: null as PaginationMeta | null,
    filtroFechaDesde: '',
    filtroFechaHasta: '',
    filtroCuentaId: null as number | null,
    filtroTipo: '' as TipoMovimiento | '',
    filtroConcepto: '',
    loading: false,
    error: null as string | null,
  }),

  actions: {
    /** UC-06: los 4 filtros son combinables entre sí. */
    async fetchList(page = 1) {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/movimientos', {
          params: {
            page,
            fecha_desde: this.filtroFechaDesde || undefined,
            fecha_hasta: this.filtroFechaHasta || undefined,
            cuenta_id: this.filtroCuentaId ?? undefined,
            tipo: this.filtroTipo || undefined,
            concepto: this.filtroConcepto || undefined,
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

    limpiarFiltros() {
      this.filtroFechaDesde = ''
      this.filtroFechaHasta = ''
      this.filtroCuentaId = null
      this.filtroTipo = ''
      this.filtroConcepto = ''
    },

    async create(payload: MovimientoPayload): Promise<Movimiento> {
      const { data } = await http.post('/movimientos', payload)
      return data.data
    },

    async update(id: number, payload: MovimientoPayload): Promise<Movimiento> {
      const { data } = await http.put(`/movimientos/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/movimientos/${id}`)
    },

    /** Crea las dos filas vinculadas de una transferencia (UC-04). */
    async crearTransferencia(payload: TransferenciaPayload): Promise<Movimiento[]> {
      const { data } = await http.post('/transferencias', payload)
      return data.data
    },
  },
})
