import axios, { type InternalAxiosRequestConfig } from 'axios'

export const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api/v1'

const http = axios.create({
  baseURL: API_URL,
  withCredentials: true,
  withXSRFToken: true,
})

/**
 * Sanctum exige una cookie CSRF antes de cualquier POST stateful
 * (login, forgot-password, reset-password). Vive fuera de /api/v1.
 */
export async function ensureCsrfCookie(): Promise<void> {
  const root = API_URL.replace(/\/api\/v1\/?$/, '')
  await axios.get(`${root}/sanctum/csrf-cookie`, { withCredentials: true })
}

/**
 * Qué hacer cuando el servidor dice que ya no hay sesión
 * (ver 044-sesion-caida-y-pantallas-trabadas.md).
 *
 * Lo decide quien conoce la aplicación —`main.ts`, que tiene el router y el store—, no este
 * módulo: si `http` importara el router entraríamos en un ciclo, porque el router importa el store
 * de auth y el store importa `http`.
 */
type ManejadorSesionCaida = () => void

let alCaerLaSesion: ManejadorSesionCaida | null = null

export function registrarSesionCaida(manejador: ManejadorSesionCaida): void {
  alCaerLaSesion = manejador
}

/** Una petición que ya pasó por el reintento de CSRF; no se reintenta dos veces. */
interface ConfigConReintento extends InternalAxiosRequestConfig {
  reintentadoTrasCsrf?: boolean
}

/**
 * Sin esto, una sesión caída deja la pantalla muerta: el guard del router solo consulta `/user` una
 * vez por pestaña, así que sigue creyendo que hay usuario mientras cada petición responde 401, y
 * nadie lleva al login.
 */
http.interceptors.response.use(
  (respuesta) => respuesta,
  async (error: unknown) => {
    if (!axios.isAxiosError(error)) return Promise.reject(error)

    const estado = error.response?.status
    const config = error.config as ConfigConReintento | undefined

    // Al expirar la sesión se va con ella la cookie XSRF-TOKEN, así que el primer POST posterior
    // no falla con 401 sino con 419. Se pide una cookie nueva y se reintenta una sola vez: si la
    // sesión sigue viva —el caso normal cuando "recordarme" la revive— el usuario no se entera.
    if (estado === 419 && config && !config.reintentadoTrasCsrf) {
      config.reintentadoTrasCsrf = true

      try {
        await ensureCsrfCookie()

        return await http.request(config)
      } catch {
        // El reintento tampoco pasó: se trata como sesión caída, igual que un 401.
      }
    }

    if (estado === 401 || estado === 419) {
      alCaerLaSesion?.()
    }

    return Promise.reject(error)
  },
)

export default http
