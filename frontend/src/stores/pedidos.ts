import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'
import type { TipoDescuento, TasaIva } from './facturas'

export type EstadoPedido = 'pendiente' | 'anticipo' | 'pagado' | 'entregado'

export interface PedidoLinea {
  id: number
  /** Null en la línea libre: algo que se vendió en mostrador y no está en el catálogo. */
  articulo_id: number | null
  es_libre: boolean
  cantidad: number
  descripcion: string
  modelo: string | null
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
  importe: number
  iva_importe: number
}

export interface PedidoPago {
  id: number
  fecha_pago: string
  monto: number
  cuenta_id: number | null
  cuenta_nombre: string | null
  /** True en el pago que cerró la venta al escanear el QR, false en los capturados a mano. */
  registrado_al_entregar: boolean
  created_at: string
}

export interface Pedido {
  id: number
  folio: number
  /** El folio con ceros a la izquierda: el "No. de ticket" que se imprime. */
  numero_ticket: string
  folio_formateado: string
  estado: EstadoPedido
  cliente_nombre: string
  cliente_telefono: string
  cliente_correo: string | null
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
  entregado_en: string | null
  puede_eliminarse: boolean
  es_editable: boolean
  factura_id: number | null
  factura_estado: string | null
  /** Motivo del último intento fallido de autofactura, o null. Alimenta la señal del listado. */
  autofactura_error: string | null
  autofactura_url: string | null
  autofactura_vence_el: string | null
  autofactura_no_disponible: string | null
  lineas: PedidoLinea[]
  pagos: PedidoPago[]
  /** Solo en el detalle: mensajes con los huecos ya resueltos y QR ya dibujado en el servidor. */
  mensaje_compartible?: string
  /** Aviso de "ya está listo", que el usuario manda cuando el trabajo lo está. */
  mensaje_listo?: string
  qr_entrega?: string | null
  url_entrega?: string
  created_at: string
  updated_at: string
}

export interface PedidoLineaPayload {
  articulo_id: number | null
  cantidad: number
  descripcion: string
  modelo: string | null
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
}

export interface PedidoPayload {
  cliente_nombre: string
  cliente_telefono: string
  cliente_correo: string | null
  descuento_global_tipo: TipoDescuento | null
  descuento_global_valor: number | null
  lineas: PedidoLineaPayload[]
  total: number
}

export interface ResultadoEntrega {
  ya_estaba_entregado: boolean
  cobrado: number
  cuenta_nombre: string | null
  pedido: Pedido
}

interface PaginationMeta {
  current_page: number
  last_page: number
  total: number
}

export const usePedidosStore = defineStore('pedidos', {
  state: () => ({
    items: [] as Pedido[],
    meta: null as PaginationMeta | null,
    filtroCliente: '',
    filtroTelefono: '',
    filtroFolio: '',
    filtroEstado: '' as EstadoPedido | '',
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
        const { data } = await http.get('/pedidos', {
          params: {
            page,
            cliente: this.filtroCliente || undefined,
            telefono: this.filtroTelefono || undefined,
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

    async fetchOne(id: number): Promise<Pedido> {
      const { data } = await http.get(`/pedidos/${id}`)
      return data.data
    },

    async create(payload: PedidoPayload): Promise<Pedido> {
      const { data } = await http.post('/pedidos', payload)
      return data.data
    },

    async update(id: number, payload: PedidoPayload): Promise<Pedido> {
      const { data } = await http.put(`/pedidos/${id}`, payload)
      return data.data
    },

    async remove(id: number) {
      await http.delete(`/pedidos/${id}`)
      this.items = this.items.filter((pedido) => pedido.id !== id)
    },

    async registrarPago(
      id: number,
      payload: { fecha_pago: string; monto: number; cuenta_id: number },
    ): Promise<Pedido> {
      const { data } = await http.post(`/pedidos/${id}/pagos`, payload)
      return data.data
    },

    async eliminarPago(pedidoId: number, pagoId: number) {
      await http.delete(`/pedidos/${pedidoId}/pagos/${pagoId}`)
    },

    /**
     * Cierra el pedido. Con saldo pendiente hay que mandar la cuenta a la que entra el dinero; sin
     * saldo, ninguna. El backend es idempotente, así que reintentar tras un corte de red no cobra
     * dos veces.
     */
    async entregar(id: number, cuentaId?: number): Promise<ResultadoEntrega> {
      const { data } = await http.post(
        `/pedidos/${id}/entregar`,
        cuentaId === undefined ? {} : { cuenta_id: cuentaId },
      )
      return data
    },

    async deshacerEntrega(id: number): Promise<Pedido> {
      const { data } = await http.post(`/pedidos/${id}/deshacer-entrega`)
      return data.pedido
    },

    /** Datos del último pedido con ese teléfono, para ofrecer rellenar la captura. */
    async buscarPorTelefono(
      telefono: string,
    ): Promise<{ encontrado: boolean; cliente_nombre?: string; cliente_correo?: string | null }> {
      const { data } = await http.get('/pedidos/por-telefono', { params: { telefono } })
      return data
    },

    /** El ticket dibujado por el servidor, como Blob, listo para compartir o descargar. */
    async ticketBlob(id: number): Promise<Blob> {
      const { data } = await http.get(`/pedidos/${id}/ticket`, { responseType: 'blob' })
      return new Blob([data], { type: 'image/jpeg' })
    },
  },
})
