import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

/**
 * Datos fiscales del negocio que encabezan los tres documentos impresos
 * (ver specs/019-formato-pdf-documentos.md).
 *
 * Es uno solo para toda la instalación, no uno por usuario: el timbrado usa una única llave de
 * Facturapi y todos emiten con el mismo certificado.
 */
export interface Emisor {
  nombre: string | null
  rfc: string | null
  regimen_fiscal: string | null
  domicilio: string | null
  correo: string | null
  telefono: string | null
  tiene_logo: boolean
  tiene_logo_marca: boolean
  esta_completo: boolean
}

export interface EmisorPayload {
  nombre: string
  rfc: string
  regimen_fiscal: string | null
  domicilio: string | null
  correo: string | null
  telefono: string | null
}

export type TipoLogo = 'principal' | 'marca'

export const useEmisorStore = defineStore('emisor', {
  state: () => ({
    datos: null as Emisor | null,
    cargado: false,
    error: null as string | null,
  }),

  getters: {
    /**
     * Falso solo cuando el emisor ya se consultó y está incompleto. Mientras no se sepa, no se
     * avisa: un aviso que parpadea en cada carga de pantalla se vuelve ruido y se deja de leer.
     */
    incompleto: (state) => state.cargado && !state.datos?.esta_completo,
  },

  actions: {
    async fetch(): Promise<Emisor> {
      try {
        const { data } = await http.get<{ data: Emisor }>('/emisor')
        this.datos = data.data
        this.cargado = true
        return data.data
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      }
    },

    /** Se consulta una sola vez por sesión; las pantallas que solo quieren el aviso usan esto. */
    async fetchUnaVez(): Promise<void> {
      if (this.cargado) {
        return
      }

      try {
        await this.fetch()
      } catch {
        // El aviso es accesorio: si no se pudo consultar, la pantalla sigue funcionando.
      }
    },

    async update(payload: EmisorPayload): Promise<Emisor> {
      const { data } = await http.put<{ data: Emisor }>('/emisor', payload)
      this.datos = data.data
      this.cargado = true
      return data.data
    },

    async subirLogo(tipo: TipoLogo, archivo: File): Promise<Emisor> {
      const cuerpo = new FormData()
      cuerpo.append('tipo', tipo)
      cuerpo.append('archivo', archivo)

      const { data } = await http.post<{ data: Emisor }>('/emisor/logo', cuerpo)
      this.datos = data.data
      return data.data
    },

    async eliminarLogo(tipo: TipoLogo): Promise<void> {
      await http.delete(`/emisor/logo/${tipo}`)
      await this.fetch()
    },

    /**
     * El logo como URL local para la vista previa. Los archivos viven en el disco privado y solo
     * salen por una ruta autenticada, así que no se pueden poner directo en un `src`.
     */
    async logoPrevio(tipo: TipoLogo): Promise<string> {
      const { data } = await http.get(`/emisor/logo/${tipo}`, { responseType: 'blob' })
      return URL.createObjectURL(data as Blob)
    },
  },
})
