import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

export type TipoDescuento = 'porcentaje' | 'monto'
export type TasaIva = '16' | '0' | 'exento'
export type MetodoPago = 'PUE' | 'PPD'
export type EstadoFactura = 'borrador' | 'pendiente' | 'timbrada' | 'cancelada'
export type EstadoCancelacion = 'pending' | 'verifying' | 'accepted'

export interface FacturaLinea {
  id: number
  articulo_id: number
  cantidad: number
  descripcion: string
  modelo: string
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
  importe: number
  iva_importe: number
}

export interface ComplementoPago {
  id: number
  factura_id: number
  fecha_pago: string
  monto: number
  forma_pago: string
  estado: 'pendiente' | 'timbrado' | 'error'
  uuid_fiscal: string | null
  cadena_original_sat: string | null
  error_timbrado: string | null
}

export interface Factura {
  id: number
  folio: number
  estado: EstadoFactura
  cliente_id: number
  cliente_razon_social: string | null
  cliente_correo: string | null
  uso_cfdi: string
  forma_pago: string
  metodo_pago: MetodoPago
  moneda: string
  tipo_comprobante: string
  descuento_global_tipo: TipoDescuento | null
  descuento_global_valor: number | null
  subtotal: number
  total_descuento: number
  total_iva_16: number
  total_iva_0: number
  total_exento: number
  total: number
  uuid_fiscal: string | null
  facturapi_serie: string | null
  facturapi_folio: number | null
  no_certificado_sat: string | null
  cadena_original_sat: string | null
  fecha_timbrado: string | null
  error_timbrado: string | null
  motivo_cancelacion: string | null
  factura_sustituta_id: number | null
  fecha_cancelacion: string | null
  estado_cancelacion: EstadoCancelacion | null
  lineas: FacturaLinea[]
  complemento_pago: ComplementoPago | null
  created_at: string
  updated_at: string
}

export interface FacturaLineaPayload {
  articulo_id: number
  cantidad: number
  descripcion: string
  modelo: string
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
}

export interface FacturaPayload {
  cliente_id: number | null
  uso_cfdi: string | null
  forma_pago: string | null
  metodo_pago: MetodoPago | null
  descuento_global_tipo: TipoDescuento | null
  descuento_global_valor: number | null
  lineas: FacturaLineaPayload[]
  total: number
  cotizacion_id?: number | null
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

function descargarBlob(data: BlobPart, nombreArchivo: string, tipo: string) {
  const url = URL.createObjectURL(new Blob([data], { type: tipo }))
  const enlace = document.createElement('a')
  enlace.href = url
  enlace.download = nombreArchivo
  enlace.click()
  URL.revokeObjectURL(url)
}

export const useFacturasStore = defineStore('facturas', {
  state: () => ({
    items: [] as Factura[],
    meta: null as PaginationMeta | null,
    search: '',
    estado: '' as EstadoFactura | '',
    loading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(page = 1) {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/facturas', {
          params: { page, search: this.search || undefined, estado: this.estado || undefined },
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

    async fetchOne(id: number): Promise<Factura> {
      const { data } = await http.get(`/facturas/${id}`)
      return data.data
    },

    async create(payload: FacturaPayload): Promise<Factura> {
      const { data } = await http.post('/facturas', payload)
      return data.data
    },

    async update(id: number, payload: FacturaPayload): Promise<Factura> {
      const { data } = await http.put(`/facturas/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/facturas/${id}`)
      this.items = this.items.filter((factura) => factura.id !== id)
    },

    async timbrar(id: number): Promise<Factura> {
      const { data } = await http.post(`/facturas/${id}/timbrar`)
      return data.data
    },

    async cancelar(
      id: number,
      payload: { motivo_cancelacion: string; factura_sustituta_id?: number | null },
    ): Promise<Factura> {
      const { data } = await http.post(`/facturas/${id}/cancelar`, payload)
      return data.data
    },

    async enviarCorreo(id: number, destinatarios: string[]) {
      await http.post(`/facturas/${id}/enviar-correo`, { destinatarios })
    },

    async registrarComplementoPago(
      id: number,
      payload: { fecha_pago: string; monto: number; forma_pago: string },
    ): Promise<ComplementoPago> {
      const { data } = await http.post(`/facturas/${id}/complemento-pago`, payload)
      return data.data
    },

    async descargarXml(factura: Factura) {
      const { data } = await http.get(`/facturas/${factura.id}/xml`, { responseType: 'blob' })
      descargarBlob(data, `factura-${factura.folio}.xml`, 'application/xml')
    },

    async descargarPdf(factura: Factura) {
      const { data } = await http.get(`/facturas/${factura.id}/pdf`, { responseType: 'blob' })
      descargarBlob(data, `factura-${factura.folio}.pdf`, 'application/pdf')
    },
  },
})
