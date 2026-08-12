import axios from 'axios'

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

export default http
