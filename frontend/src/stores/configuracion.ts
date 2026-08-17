import { defineStore } from 'pinia'
import http from '../lib/http'
import { extractErrorMessage } from '../lib/errors'

/**
 * Ajustes globales del usuario (ver specs/014-costo-elaboracion-goma.md).
 *
 * El backend responde siempre con todas las claves y su valor efectivo — el guardado o el de
 * fábrica —, así que esta capa nunca tiene que saber qué es un valor por defecto.
 */
export interface Configuracion {
  costo_goma_chica: string
  costo_goma_mediana: string
  costo_goma_grande: string
  /** Mensaje que acompaña al ticket de mostrador al compartirlo (ver specs/027). */
  mensaje_ticket: string
  /** Aviso de que el pedido ya se puede recoger, que el usuario manda a mano (ver specs/027). */
  mensaje_listo: string
}

export type ConfiguracionPayload = Partial<Record<keyof Configuracion, string | number>>

export const useConfiguracionStore = defineStore('configuracion', {
  state: () => ({
    valores: null as Configuracion | null,
    loading: false,
    error: null as string | null,
  }),

  getters: {
    /** Costo vigente de un tamaño de goma, listo para la cadena de cálculo. */
    costoGoma: (state) => {
      return (tamano: string | null | undefined): number => {
        if (!tamano || !state.valores) {
          return 0
        }

        return Number(state.valores[`costo_goma_${tamano}` as keyof Configuracion] ?? 0)
      }
    },
  },

  actions: {
    async fetch(): Promise<Configuracion> {
      this.loading = true
      this.error = null

      try {
        const { data } = await http.get<Configuracion>('/configuracion')
        this.valores = data
        return data
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      } finally {
        this.loading = false
      }
    },

    /**
     * Conteo exacto de artículos cuyo precio de venta cambiaría con los valores propuestos.
     * Alimenta el diálogo de confirmación; no persiste nada.
     */
    async impactoPrecios(payload: ConfiguracionPayload): Promise<number> {
      const { data } = await http.get<{ articulos_afectados: number }>(
        '/configuracion/impacto-precios',
        { params: payload },
      )

      return data.articulos_afectados
    },

    async update(payload: ConfiguracionPayload): Promise<Configuracion> {
      const { data } = await http.put<Configuracion>('/configuracion', payload)
      this.valores = data
      return data
    },
  },
})
