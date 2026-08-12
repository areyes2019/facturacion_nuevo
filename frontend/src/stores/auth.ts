import axios from 'axios'
import { defineStore } from 'pinia'
import http, { ensureCsrfCookie } from '../lib/http'

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
      } catch {
        this.user = null
      } finally {
        this.initialized = true
      }
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
