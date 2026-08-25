import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

export type EstadoOrdenTrabajo =
  'pendiente' | 'en_produccion' | 'listo_para_entregar' | 'a_domicilio' | 'entregado'

export type DocumentableType = 'pedido' | 'cotizacion'

export type TarifaEnvio = 'a' | 'b' | 'c'
export type FormaPagoEnvio = 'prepagado' | 'por_cobrar'

export interface Envio {
  id: number
  nombre_receptor: string
  telefono_receptor: string
  fecha_recepcion: string
  hora_recepcion: string
  tarifa: TarifaEnvio
  monto: number
  forma_pago: FormaPagoEnvio
  created_at: string
}

export interface OrdenTrabajo {
  id: number
  folio: number
  folio_formateado: string
  estado: EstadoOrdenTrabajo
  observaciones: string | null
  imagen_url: string | null
  documentable_type: DocumentableType
  documentable_id: number
  documento_etiqueta: string
  cliente_nombre: string | null
  cliente_telefono: string | null
  producto: string | null
  saldo_pendiente: number
  envio: Envio | null
  created_at: string
  updated_at: string
}

export interface EnvioPayload {
  nombre_receptor: string
  telefono_receptor: string
  fecha_recepcion: string
  hora_recepcion: string
  tarifa: TarifaEnvio
  forma_pago: FormaPagoEnvio
  cuenta_id?: number
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

/** Estados que se muestran en el tablero por defecto: todo salvo lo ya entregado. */
export const ESTADOS_TABLERO: EstadoOrdenTrabajo[] = [
  'pendiente',
  'en_produccion',
  'listo_para_entregar',
  'a_domicilio',
]

export const useOrdenesTrabajoStore = defineStore('ordenesTrabajo', {
  state: () => ({
    items: [] as OrdenTrabajo[],
    meta: null as PaginationMeta | null,
    filtroEstado: [...ESTADOS_TABLERO] as EstadoOrdenTrabajo[],
    loading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(page = 1) {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/ordenes-trabajo', {
          params: { page, estado: this.filtroEstado.join(',') },
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

    async fetchOne(id: number): Promise<OrdenTrabajo> {
      const { data } = await http.get(`/ordenes-trabajo/${id}`)
      return data.data
    },

    async create(
      documentableType: DocumentableType,
      documentableId: number,
    ): Promise<OrdenTrabajo> {
      const { data } = await http.post('/ordenes-trabajo', {
        documentable_type: documentableType,
        documentable_id: documentableId,
      })
      return data.data
    },

    async actualizarObservaciones(id: number, observaciones: string): Promise<OrdenTrabajo> {
      const { data } = await http.put(`/ordenes-trabajo/${id}`, { observaciones })
      return data.data
    },

    async subirImagen(id: number, archivo: File): Promise<OrdenTrabajo> {
      const form = new FormData()
      form.append('archivo', archivo)
      const { data } = await http.post(`/ordenes-trabajo/${id}/imagen`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return data.data
    },

    async eliminarImagen(id: number) {
      await http.delete(`/ordenes-trabajo/${id}/imagen`)
    },

    async iniciarProduccion(id: number): Promise<OrdenTrabajo> {
      const { data } = await http.post(`/ordenes-trabajo/${id}/iniciar-produccion`)
      return data.data
    },

    async marcarListo(id: number): Promise<OrdenTrabajo> {
      const { data } = await http.post(`/ordenes-trabajo/${id}/marcar-listo`)
      return data.data
    },

    async crearEnvio(id: number, payload: EnvioPayload): Promise<OrdenTrabajo> {
      const { data } = await http.post(`/ordenes-trabajo/${id}/envio`, payload)
      return data.data
    },

    async entregar(id: number): Promise<OrdenTrabajo> {
      const { data } = await http.post(`/ordenes-trabajo/${id}/entregar`)
      return data.data
    },
  },
})
