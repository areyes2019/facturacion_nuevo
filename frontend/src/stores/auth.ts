import axios from 'axios'
import { defineStore } from 'pinia'
import http, { ensureCsrfCookie } from '../lib/http'
import { esErrorSinConexion } from '../lib/errors'
import { olvidarListas } from '../lib/memoriaLista'

export interface AuthUser {
  id: number
  name: string
  email: string
}

interface LoginPayload {
  email: string
  password: string
  remember: boolean
}

interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

function extractErrorMessage(err: unknown): string {
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as
      { message?: string; errors?: Record<string, string[]> } | undefined
    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined

    return firstFieldError ?? data?.message ?? 'Ocurrió un error inesperado.'
  }

  return 'Ocurrió un error inesperado.'
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as AuthUser | null,
    loading: false,
    error: null as string | null,
    initialized: false,
  }),

  getters: {
    isAuthenticated: (state) => state.user !== null,
  },

  actions: {
    async fetchUser() {
      try {
        const { data } = await http.get<AuthUser>('/user')
        this.user = data
        this.initialized = true
      } catch (err) {
        // Que la pregunta no llegue a ningún lado no es lo mismo que no tener sesión: en un
        // mostrador con wifi intermitente, tratarlo igual sacaría al usuario al login teniendo la
        // sesión viva (ver 044-sesion-caida-y-pantallas-trabadas.md). Se conserva lo que había y
        // se deja sin inicializar para que el guard lo reintente en la siguiente navegación.
        if (esErrorSinConexion(err)) return

        this.user = null
        this.initialized = true
      }
    },

    /**
     * El servidor dijo que ya no hay sesión. Lo llama el interceptor de `http`, que es quien se
     * entera primero, a media pantalla y mucho antes de que el guard vuelva a preguntar.
     */
    marcarSesionCaida() {
      this.user = null
      // Se vuelve a consultar `/user` en la siguiente navegación en vez de dar por sentado el
      // estado que acaba de quedar inválido.
      this.initialized = false
      olvidarListas()
    },

    async login(payload: LoginPayload) {
      this.loading = true
      this.error = null

      try {
        await ensureCsrfCookie()
        await http.post('/auth/login', payload)
        await this.fetchUser()
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      } finally {
        this.loading = false
      }
    },

    async logout() {
      await http.post('/auth/logout')
      this.user = null
      // Lo que las listas del mostrador traían cargado es de la sesión que acaba de cerrarse
      // (ver 031-mostrador-consulta.md).
      olvidarListas()
    },

    async forgotPassword(email: string) {
      this.loading = true
      this.error = null

      try {
        await ensureCsrfCookie()
        await http.post('/auth/forgot-password', { email })
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      } finally {
        this.loading = false
      }
    },

    async resetPassword(payload: ResetPasswordPayload) {
      this.loading = true
      this.error = null

      try {
        await ensureCsrfCookie()
        await http.post('/auth/reset-password', payload)
      } catch (err) {
        this.error = extractErrorMessage(err)
        throw err
      } finally {
        this.loading = false
      }
    },
  },
})
