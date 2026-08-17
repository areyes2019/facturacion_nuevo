import axios from 'axios'

interface ValidationErrorResponse {
  message?: string
  errors?: Record<string, string[]>
}

export function extractErrorMessage(err: unknown): string {
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as ValidationErrorResponse | undefined
    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined

    return firstFieldError ?? data?.message ?? 'Ocurrió un error inesperado.'
  }

  return 'Ocurrió un error inesperado.'
}

/**
 * Lo que se dice cuando la petición no llegó a ningún lado (ver 029-pwa-mostrador.md).
 *
 * La aplicación instalada abre aunque no haya internet —su interfaz está en el precache—, así que
 * la falta de conexión aparece justo cuando una pantalla necesita datos. Decirlo con estas palabras
 * es mejor que una pantalla en blanco o que el error crudo del navegador.
 */
export const MENSAJE_SIN_CONEXION = 'Sin conexión. Revisa el internet e inténtalo de nuevo.'

/** Una petición sin respuesta: el aparato no alcanzó al servidor, no es que el servidor fallara. */
export function esErrorSinConexion(err: unknown): boolean {
  return axios.isAxiosError(err) && err.response === undefined
}

/** El mensaje del servidor, o el de falta de conexión cuando nunca hubo servidor que respondiera. */
export function mensajeDeFalla(err: unknown): string {
  return esErrorSinConexion(err) ? MENSAJE_SIN_CONEXION : extractErrorMessage(err)
}

/**
 * El mensaje de una descarga fallida (ver 029-pwa-mostrador.md, supuesto 80).
 *
 * Pedir un archivo con `responseType: 'blob'` envuelve **también** el JSON de error del servidor, así
 * que sin desenvolverlo un 404 o un 502 se verían como un "error inesperado" que no dice nada de lo
 * que pasó. Es asíncrona porque leer un `Blob` lo es.
 */
export async function mensajeDeFallaDeDescarga(err: unknown): Promise<string> {
  const cuerpo = axios.isAxiosError(err) ? err.response?.data : undefined

  if (cuerpo instanceof Blob) {
    try {
      const data = JSON.parse(await cuerpo.text()) as ValidationErrorResponse
      const primerCampo = data.errors ? Object.values(data.errors)[0]?.[0] : undefined

      if (primerCampo ?? data.message) return primerCampo ?? (data.message as string)
    } catch {
      // No era JSON: se sigue por el mensaje genérico de abajo.
    }
  }

  return mensajeDeFalla(err)
}

export function extractFieldErrors(err: unknown): Record<string, string> {
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as ValidationErrorResponse | undefined
    const entries = Object.entries(data?.errors ?? {}).map(([field, messages]) => [
      field,
      messages[0],
    ])

    return Object.fromEntries(entries)
  }

  return {}
}
