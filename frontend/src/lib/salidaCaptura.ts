import { ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'

/**
 * Confirmación al abandonar una captura por pasos a medias (ver 029-pwa-mostrador.md).
 *
 * Vive en el guard de la ruta y no en el botón de regreso porque en un celular el gesto de "atrás"
 * está a un dedo de distancia todo el tiempo: hay que atrapar también ese, y el título de la barra
 * superior, y cualquier otro camino que saque de la pantalla.
 *
 * @param hayCaptura Si en este momento hay trabajo capturado que se perdería al salir.
 */
export function useConfirmarSalida(hayCaptura: () => boolean) {
  const confirmandoSalida = ref(false)

  let responder: ((salir: boolean) => void) | null = null

  onBeforeRouteLeave(async () => {
    if (!hayCaptura()) return true

    confirmandoSalida.value = true

    return await new Promise<boolean>((resolve) => {
      responder = resolve
    })
  })

  function resolverSalida(salir: boolean) {
    confirmandoSalida.value = false
    responder?.(salir)
    responder = null
  }

  return {
    confirmandoSalida,
    /** Sale y pierde lo capturado. */
    confirmarSalida: () => resolverSalida(true),
    /** Se queda donde estaba. */
    cancelarSalida: () => resolverSalida(false),
  }
}
