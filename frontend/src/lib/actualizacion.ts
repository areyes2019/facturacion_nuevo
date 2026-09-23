import { useRegisterSW } from 'virtual:pwa-register/vue'

/**
 * Registro único del service worker (ver 044-sesion-caida-y-pantallas-trabadas.md).
 *
 * Lo consumen dos lugares que tienen que hablar del **mismo** registro: la barra
 * `AvisoActualizacion.vue`, que ofrece recargar cuando hay versión nueva, y el manejador de
 * `vite:preloadError` de `main.ts`, que necesita activar esa versión nueva cuando una pantalla ya
 * no puede cargar. Registrarlo dos veces dejaría a cada uno mirando su propio service worker.
 *
 * `useRegisterSW` no usa hooks de ciclo de vida —solo `ref`—, así que vive bien fuera de un
 * componente.
 */
const { needRefresh, updateServiceWorker } = useRegisterSW()

/** Si el service worker ya tiene lista una versión nueva, esperando a que alguien la acepte. */
export const hayVersionNueva = needRefresh

/**
 * Cuánto se espera antes de recargar por nuestra cuenta.
 *
 * `updateServiceWorker()` **no recarga**: solo manda `skipWaiting`, y la recarga la dispara después
 * el evento `controlling`, que únicamente ocurre si de verdad había una versión esperando. Cuando
 * no la había, esa recarga nunca llega y hay que hacerla a mano.
 */
const ESPERA_ANTES_DE_RECARGAR = 1000

/**
 * Toma la versión nueva y recarga. Si no hay ninguna esperando, recarga de todos modos: quien llama
 * ya se topó con una pantalla que no carga, y quedarse igual no es una opción.
 */
export async function aplicarActualizacion(): Promise<void> {
  try {
    await updateServiceWorker(true)
  } catch {
    // Si el service worker ni siquiera respondió, la recarga de abajo sigue siendo lo correcto.
  }

  window.setTimeout(() => window.location.reload(), ESPERA_ANTES_DE_RECARGAR)
}
