import { defineStore } from 'pinia'
import http from '../lib/http'
import {
  compartirArchivo,
  type ArchivoCompartible,
  type ResultadoCompartir,
} from '../lib/compartir'
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
  cliente_rfc: string | null
  cliente_correo: string | null
  /** Vigente, no congelado (ver 033-precio-distribuidor.md). */
  cliente_es_distribuidor: boolean
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
  /** Centavos que cierran el total en peso cerrado (ver specs/030-total-al-peso-cerrado.md). */
  ajuste_al_peso: number
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

    async archivoBlob(id: number, tipo: 'pdf' | 'xml'): Promise<Blob> {
      const { data } = await http.get(`/facturas/${id}/${tipo}`, { responseType: 'blob' })

      return data
    },

    async descargarXml(factura: Factura) {
      const blob = await this.archivoBlob(factura.id, 'xml')

      descargarBlob(blob, `factura-${factura.folio}.xml`, 'application/xml')
    },

    async descargarPdf(factura: Factura) {
      const blob = await this.archivoBlob(factura.id, 'pdf')

      descargarBlob(blob, `factura-${factura.folio}.pdf`, 'application/pdf')
    },

    /**
     * Baja **solo el PDF**. El XML no puede salir por el menú del aparato —Chrome no admite `.xml`
     * entre los tipos que comparte— así que ni siquiera se pide: una petición que viaja hasta
     * facturapi por un archivo que nunca va a mandarse es una espera regalada. Al cliente le llega
     * por correo (ver 029-pwa-mostrador.md, supuestos 81 y 82).
     *
     * Se llama antes del toque —al entrar a la pantalla, o al pasar el puntero por el botón del
     * listado—, nunca al apretarlo: esperar a la descarga después del gesto lo agota, y el menú de
     * compartir solo se abre mientras ese gesto sigue vivo (supuesto 78).
     */
    async archivoPdf(factura: Factura): Promise<ArchivoCompartible> {
      return {
        contenido: await this.archivoBlob(factura.id, 'pdf'),
        nombre: `factura-${factura.folio}.pdf`,
      }
    },

    mensajeWhatsapp(factura: Factura): string {
      return `Factura ${factura.folio} por un total de $${factura.total.toFixed(2)} MXN.`
    },

    /**
     * Entrega al menú del aparato el PDF que ya está bajado; donde no hay menú, abre WhatsApp con el
     * mensaje y el archivo descargado.
     *
     * No cambia el estado de la factura: una timbrada ya está timbrada, y no hay un "enviada" que
     * mover como en la cotización.
     */
    async compartirPorWhatsapp(
      factura: Factura,
      archivo: ArchivoCompartible,
    ): Promise<ResultadoCompartir> {
      return compartirArchivo(archivo.contenido, archivo.nombre, this.mensajeWhatsapp(factura))
    },

    /**
     * Entrega el PDF al **menú de compartir del sistema** —en Windows 11, el catálogo de envío con
     * Drive, WhatsApp, Correo y lo que el usuario tenga instalado— **sin texto que lo acompañe**:
     * el destino puede ser una carpeta o Drive, donde un mensaje pegado sobra, y quien lo mande por
     * WhatsApp escribe lo suyo (ver 007-facturacion.md).
     *
     * Es el compartir del escritorio. El del mostrador es `compartirPorWhatsapp`, que sí lleva su
     * resumen porque ahí el canal ya está elegido.
     */
    async compartirPdf(archivo: ArchivoCompartible): Promise<ResultadoCompartir> {
      return compartirArchivo(archivo.contenido, archivo.nombre)
    },
  },
})
