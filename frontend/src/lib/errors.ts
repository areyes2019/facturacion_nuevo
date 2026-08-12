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
