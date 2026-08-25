import { defineStore } from 'pinia'
import http from '../lib/http'
import {
  compartirArchivo,
  type ArchivoCompartible,
  type ResultadoCompartir,
} from '../lib/compartir'
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
  /** Vigente, no congelado (ver 033-precio-distribuidor.md). */
  cliente_es_distribuidor: boolean
  /** Copia congelada del descuento que tenía el cliente al capturar la cotización. */
  descuento_cliente_porcentaje: number
  descuento_global_tipo: TipoDescuento | null
  descuento_global_valor: number | null
  subtotal: number
  total_descuento: number
  total_iva_16: number
  total_iva_0: number
  total_exento: number
  /** Centavos que cierran el total en peso cerrado (ver specs/030-total-al-peso-cerrado.md). */
  ajuste_al_peso: number
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
  /** Momento en que el escaneo del QR cerró la cotización (ver 038-produccion-ordenes-trabajo.md). */
  entregado_en: string | null
  /** Orden de Trabajo de Producción, si esta cotización ya tiene una (ver 038). */
  orden_trabajo_id?: number | null
  orden_trabajo_estado?: string | null
  /** Solo en el detalle: QR ya dibujado en el servidor, mismo mecanismo que Pedido (027). */
  qr_entrega?: string | null
  url_entrega?: string
  created_at: string
  updated_at: string
}

export interface ResultadoEntregaCotizacion {
  ya_estaba_entregado: boolean
  cobrado: number
  cuenta_nombre: string | null
  cotizacion: Cotizacion
}

export interface CotizacionLineaPayload {
  /**
   * Nullable solo por el tipo compartido de `DocumentoLineas`, que desde 027 admite líneas sin
   * artículo. Aquí el buscador siempre pone un id y el backend lo exige (`required`).
   */
  articulo_id: number | null
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

    /**
     * Solo correo: el WhatsApp lo comparte el propio aparato con el PDF que baja `pdfBlob`, así que
     * no hay envío que pedirle al servidor (ver 029-pwa-mostrador.md).
     */
    async enviar(id: number, payload: { canal: 'correo'; destinatarios: string[] }) {
      await http.post(`/cotizaciones/${id}/enviar`, payload)
    },

    /**
     * Cierra el envío por WhatsApp, que ocurrió fuera del sistema: sin este aviso la cotización se
     * quedaría en borrador aunque el cliente ya la tenga.
     */
    async marcarEnviada(id: number) {
      await http.post(`/cotizaciones/${id}/marcar-enviada`)
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

    /**
     * Destino del QR de la cotización (ver 038-produccion-ordenes-trabajo.md): con saldo pendiente
     * hay que mandar la cuenta a la que entra el dinero; sin saldo, ninguna.
     */
    async entregar(id: number, cuentaId?: number): Promise<ResultadoEntregaCotizacion> {
      const { data } = await http.post(
        `/cotizaciones/${id}/entregar`,
        cuentaId === undefined ? {} : { cuenta_id: cuentaId },
      )
      return data
    },

    async deshacerEntrega(id: number): Promise<Cotizacion> {
      const { data } = await http.post(`/cotizaciones/${id}/deshacer-entrega`)
      return data.cotizacion
    },

    async duplicar(id: number): Promise<Cotizacion> {
      const { data } = await http.post(`/cotizaciones/${id}/duplicar`)
      return data.data
    },

    /**
     * Baja lo que se va a mandar por WhatsApp. Se llama **antes** de que el usuario toque el botón:
     * el menú del aparato solo se abre mientras su gesto sigue vivo, y esperar a una descarga en
     * medio lo agota (ver 029-pwa-mostrador.md, supuesto 78).
     */
    async archivoParaWhatsapp(cotizacion: Cotizacion): Promise<ArchivoCompartible> {
      return {
        contenido: await this.pdfBlob(cotizacion.id),
        nombre: `cotizacion-${cotizacion.folio}.pdf`,
      }
    },

    mensajeWhatsapp(cotizacion: Cotizacion): string {
      return `Cotización ${cotizacion.folio} por un total de $${cotizacion.total.toFixed(2)} MXN.`
    },

    /**
     * Entrega al menú de compartir del aparato lo que `archivoParaWhatsapp` ya bajó. No descarga
     * nada aquí: la primera cosa que ocurre es el compartir, con el gesto del usuario todavía vivo.
     *
     * Solo marca la cotización como enviada si el usuario **no** canceló el menú: cancelar es no
     * haber mandado nada, y un estado que miente es peor que uno que se quedó corto.
     */
    async compartirPorWhatsapp(
      cotizacion: Cotizacion,
      archivo: ArchivoCompartible,
    ): Promise<ResultadoCompartir> {
      const resultado = await compartirArchivo(
        archivo.contenido,
        archivo.nombre,
        this.mensajeWhatsapp(cotizacion),
      )

      if (resultado !== 'cancelado') await this.marcarEnviada(cotizacion.id)

      return resultado
    },

    /** El PDF generado al vuelo, para descargarlo o para pasárselo al menú de compartir. */
    async pdfBlob(id: number): Promise<Blob> {
      const { data } = await http.get(`/cotizaciones/${id}/pdf`, { responseType: 'blob' })

      return data
    },

    async descargarPdf(cotizacion: Cotizacion) {
      const blob = await this.pdfBlob(cotizacion.id)

      descargarBlob(blob, `cotizacion-${cotizacion.folio}.pdf`, 'application/pdf')
    },

    /**
     * El PDF del recibo de un pago concreto, dibujado por el servidor al vuelo (ver
     * 040-recibo-anticipo-cotizacion.md), listo para compartir.
     */
    async reciboPagoBlob(cotizacionId: number, pagoId: number): Promise<Blob> {
      const { data } = await http.get(`/cotizaciones/${cotizacionId}/pagos/${pagoId}/recibo`, {
        responseType: 'blob',
      })

      return data
    },
  },
})
