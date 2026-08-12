import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'
import type { TipoDescuento, TasaIva } from './facturas'

export type EstadoCotizacion = 'borrador' | 'enviada' | 'pagada' | 'producto_entregado'
export type TipoPagoCotizacion = 'anticipo' | 'saldo' | 'pago_total'

export interface CotizacionLinea {
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
  /**
   * Precio unitario con el descuento de línea ya plegado adentro, calculado en backend, con el que
   * esta línea viaja a la factura (ver 015-descuento-permanente-cliente.md).
   */
  precio_unitario_facturacion: number
}

export interface CotizacionPago {
  id: number
  tipo: TipoPagoCotizacion
  fecha_pago: string
  monto: number
  cuenta_id: number | null
  cuenta_nombre: string | null
  created_at: string
}

export interface Cotizacion {
  id: number
  folio: number
  estado: EstadoCotizacion
  cliente_id: number
  cliente_razon_social: string | null
  cliente_rfc: string | null
  cliente_correo: string | null
  cliente_telefono: string | null
  /** Copia congelada del descuento que tenía el cliente al capturar la cotización. */
  descuento_cliente_porcentaje: number
  descuento_global_tipo: TipoDescuento | null
  descuento_global_valor: number | null
  subtotal: number
  total_descuento: number
  total_iva_16: number
  total_iva_0: number
  total_exento: number
  total: number
  total_pagado: number
  saldo_pendiente: number
  factura_id: number | null
  factura_estado: string | null
  /** Regla de borrado evaluada en el servidor: borrador/enviada, sin pagos y sin factura. */
  puede_eliminarse: boolean
  /**
   * Fecha en que el borrado automático por inactividad se la llevaría (30 días sin movimiento), o
   * null si no caduca. Ver 008-cotizaciones.md, "Caducidad automática".
   */
  caduca_el: string | null
  lineas: CotizacionLinea[]
  pagos: CotizacionPago[]
  created_at: string
  updated_at: string
}

export interface CotizacionLineaPayload {
  articulo_id: number
  cantidad: number
  descripcion: string
  modelo: string
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
}

export interface CotizacionPayload {
  cliente_id: number | null
  descuento_global_tipo: TipoDescuento | null
  descuento_global_valor: number | null
  lineas: CotizacionLineaPayload[]
  total: number
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

export const useCotizacionesStore = defineStore('cotizaciones', {
  state: () => ({
    items: [] as Cotizacion[],
    meta: null as PaginationMeta | null,
    filtroCliente: '',
    filtroRfc: '',
    filtroFolio: '',
    filtroEstado: '' as EstadoCotizacion | '',
    filtroFechaDesde: '',
    filtroFechaHasta: '',
    loading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(page = 1) {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get('/cotizaciones', {
          params: {
            page,
            cliente: this.filtroCliente || undefined,
            rfc: this.filtroRfc || undefined,
            folio: this.filtroFolio || undefined,
            estado: this.filtroEstado || undefined,
            fecha_desde: this.filtroFechaDesde || undefined,
            fecha_hasta: this.filtroFechaHasta || undefined,
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

    async fetchOne(id: number): Promise<Cotizacion> {
      const { data } = await http.get(`/cotizaciones/${id}`)
      return data.data
    },

    async create(payload: CotizacionPayload): Promise<Cotizacion> {
      const { data } = await http.post('/cotizaciones', payload)
      return data.data
    },

    async update(id: number, payload: CotizacionPayload): Promise<Cotizacion> {
      const { data } = await http.put(`/cotizaciones/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/cotizaciones/${id}`)
      this.items = this.items.filter((cotizacion) => cotizacion.id !== id)
    },

    async enviar(
      id: number,
      payload:
        { canal: 'correo'; destinatarios: string[] } | { canal: 'whatsapp'; telefono: string },
    ) {
      await http.post(`/cotizaciones/${id}/enviar`, payload)
    },

    async registrarPago(
      id: number,
      payload: {
        tipo: TipoPagoCotizacion
        fecha_pago: string
        monto: number | null
        cuenta_id: number
      },
    ): Promise<Cotizacion> {
      const { data } = await http.post(`/cotizaciones/${id}/pagos`, payload)
      return data.data
    },

    /**
     * Solo se permite sobre el pago más reciente (LIFO): revierte su movimiento en Tesorería y,
     * si la cotización ya no alcanza su total, la regresa de `pagada` a `enviada`.
     */
    async eliminarPago(cotizacionId: number, pagoId: number) {
      await http.delete(`/cotizaciones/${cotizacionId}/pagos/${pagoId}`)
    },

    async entregar(id: number): Promise<Cotizacion> {
      const { data } = await http.post(`/cotizaciones/${id}/entregar`)
      return data.data
    },

    async duplicar(id: number): Promise<Cotizacion> {
      const { data } = await http.post(`/cotizaciones/${id}/duplicar`)
      return data.data
    },

    async descargarPdf(cotizacion: Cotizacion) {
      const { data } = await http.get(`/cotizaciones/${cotizacion.id}/pdf`, {
        responseType: 'blob',
      })
      descargarBlob(data, `cotizacion-${cotizacion.folio}.pdf`, 'application/pdf')
    },
  },
})
