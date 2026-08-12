import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'
import type { TipoDescuento, TasaIva } from './facturas'

export type EstadoOrdenCompra = 'borrador' | 'enviada' | 'pagada' | 'recibida'

export interface OrdenCompraLinea {
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

export interface OrdenCompra {
  id: number
  folio: number
  folio_formateado: string
  estado: EstadoOrdenCompra
  proveedor_id: number
  proveedor_nombre_comercial: string | null
  proveedor_rfc: string | null
  proveedor_correo: string | null
  proveedor_telefono: string | null
  fecha_entrega_esperada: string | null
  observaciones: string | null
  descuento_global_tipo: TipoDescuento | null
  descuento_global_valor: number | null
  subtotal: number
  total_descuento: number
  total_iva_16: number
  total_iva_0: number
  total_exento: number
  total: number
  cuenta_id: number | null
  cuenta_nombre: string | null
  fecha_pago: string | null
  esta_pagada: boolean
  lineas: OrdenCompraLinea[]
  created_at: string
  updated_at: string
}

export interface OrdenCompraLineaPayload {
  articulo_id: number
  cantidad: number
  descripcion: string
  modelo: string
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
}

export interface OrdenCompraPayload {
  proveedor_id: number | null
  fecha_entrega_esperada: string | null
  observaciones: string | null
  descuento_global_tipo: TipoDescuento | null
  descuento_global_valor: number | null
  lineas: OrdenCompraLineaPayload[]
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

export const useOrdenesCompraStore = defineStore('ordenesCompra', {
  state: () => ({
    items: [] as OrdenCompra[],
    meta: null as PaginationMeta | null,
    filtroProveedor: '',
    filtroRfc: '',
    filtroFolio: '',
    filtroEstado: '' as EstadoOrdenCompra | '',
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
        const { data } = await http.get('/ordenes-compra', {
          params: {
            page,
            proveedor: this.filtroProveedor || undefined,
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

    async fetchOne(id: number): Promise<OrdenCompra> {
      const { data } = await http.get(`/ordenes-compra/${id}`)
      return data.data
    },

    async create(payload: OrdenCompraPayload): Promise<OrdenCompra> {
      const { data } = await http.post('/ordenes-compra', payload)
      return data.data
    },

    async update(id: number, payload: OrdenCompraPayload): Promise<OrdenCompra> {
      const { data } = await http.put(`/ordenes-compra/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/ordenes-compra/${id}`)
      this.items = this.items.filter((orden) => orden.id !== id)
    },

    async enviar(
      id: number,
      payload:
        { canal: 'correo'; destinatarios: string[] } | { canal: 'whatsapp'; telefono: string },
    ) {
      await http.post(`/ordenes-compra/${id}/enviar`, payload)
    },

    /**
     * Pago de contado: único y por el total de la orden. El monto no viaja en la petición — lo
     * toma el servidor de la propia orden (ver 012-ordenes-compra.md).
     */
    async pagar(
      id: number,
      payload: { cuenta_id: number; fecha_pago: string },
    ): Promise<OrdenCompra> {
      const { data } = await http.post(`/ordenes-compra/${id}/pagar`, payload)
      return data.data
    },

    /**
     * Revierte el pago: elimina el egreso en Tesorería, recalcula el saldo de la cuenta y regresa
     * la orden a `enviada`, con lo que vuelve a ser editable.
     */
    async cancelarPago(id: number): Promise<OrdenCompra> {
      const { data } = await http.delete(`/ordenes-compra/${id}/pago`)
      return data.data
    },

    async recibir(id: number): Promise<OrdenCompra> {
      const { data } = await http.post(`/ordenes-compra/${id}/recibir`)
      return data.data
    },

    async duplicar(id: number): Promise<OrdenCompra> {
      const { data } = await http.post(`/ordenes-compra/${id}/duplicar`)
      return data.data
    },

    async descargarPdf(orden: OrdenCompra) {
      const { data } = await http.get(`/ordenes-compra/${orden.id}/pdf`, {
        responseType: 'blob',
      })
      descargarBlob(data, `orden-compra-${orden.folio}.pdf`, 'application/pdf')
    },
  },
})
