import { defineStore } from 'pinia'
import http, { API_URL } from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

/**
 * Cuentas bancarias que se imprimen en la cotización para que el cliente pague
 * (ver specs/026-datos-bancarios-cotizacion.md).
 *
 * No son las Cuentas de Tesorería (`stores/cuentas.ts`): aquéllas guardan dinero y saldo, éstas
 * solo se imprimen. Tampoco viven en `stores/configuracion.ts`, que es el almacén clave→valor de
 * los costos de goma.
 */
export interface DatoBancario {
  id: number
  nombre_banco: string
  beneficiario: string | null
  numero_cuenta: string | null
  tarjeta: string | null
  clabe: string | null
  visible_en_cotizaciones: boolean
  orden: number
  tiene_logo: boolean
  /**
   * Los 8 caracteres al azar del nombre del archivo. Se pegan a la dirección del icono (`?v=...`)
   * para que reemplazar un logo se vea de inmediato, sin vaciar la caché del navegador.
   */
  logo_version: string | null
}

export interface DatoBancarioPayload {
  nombre_banco: string
  beneficiario: string | null
  numero_cuenta: string | null
  tarjeta: string | null
  clabe: string | null
  visible_en_cotizaciones: boolean
}

export const useDatosBancariosStore = defineStore('datosBancarios', {
  state: () => ({
    bancos: [] as DatoBancario[],
    cargado: false,
    error: null as string | null,
  }),

  actions: {
    async fetch(): Promise<DatoBancario[]> {
      try {
        const { data } = await http.get<{ data: DatoBancario[] }>('/datos-bancarios')
        this.bancos = data.data
        this.cargado = true
        return data.data
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      }
    },

    async crear(payload: DatoBancarioPayload): Promise<DatoBancario> {
      const { data } = await http.post<{ data: DatoBancario }>('/datos-bancarios', payload)
      this.bancos.push(data.data)
      return data.data
    },

    async actualizar(id: number, payload: DatoBancarioPayload): Promise<DatoBancario> {
      const { data } = await http.put<{ data: DatoBancario }>(`/datos-bancarios/${id}`, payload)
      const indice = this.bancos.findIndex((banco) => banco.id === id)
      if (indice !== -1) {
        this.bancos[indice] = data.data
      }
      return data.data
    },

    async eliminar(id: number): Promise<void> {
      await http.delete(`/datos-bancarios/${id}`)
      this.bancos = this.bancos.filter((banco) => banco.id !== id)
    },

    /**
     * Manda el orden completo, no la posición de uno solo: el backend rechaza una lista parcial
     * porque dejaría posiciones repetidas entre lo enviado y lo que se quedó.
     */
    async reordenar(ids: number[]): Promise<DatoBancario[]> {
      const { data } = await http.put<{ data: DatoBancario[] }>('/datos-bancarios/orden', { ids })
      this.bancos = data.data
      return data.data
    },

    async subirLogo(id: number, archivo: File): Promise<DatoBancario> {
      const cuerpo = new FormData()
      cuerpo.append('archivo', archivo)

      const { data } = await http.post<{ data: DatoBancario }>(
        `/datos-bancarios/${id}/logo`,
        cuerpo,
      )
      this.reemplazar(data.data)
      return data.data
    },

    async eliminarLogo(id: number): Promise<void> {
      await http.delete(`/datos-bancarios/${id}/logo`)
      await this.fetch()
    },

    reemplazar(banco: DatoBancario) {
      const indice = this.bancos.findIndex((actual) => actual.id === banco.id)
      if (indice !== -1) {
        this.bancos[indice] = banco
      }
    },
  },
})

/**
 * Dirección del icono de un banco. Los archivos viven en el disco privado y solo salen por una ruta
 * autenticada, así que se compone fuera del cliente HTTP para poder ponerla en un `src` (mismo
 * caso que la imagen de artículo en specs/020).
 */
export function urlLogoBanco(banco: DatoBancario): string {
  return `${API_URL}/datos-bancarios/${banco.id}/logo?v=${banco.logo_version ?? ''}`
}
