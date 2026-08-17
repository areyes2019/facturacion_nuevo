import { ref } from 'vue'

/**
 * Instalación de la aplicación desde un botón propio (ver 029-pwa-mostrador.md).
 *
 * Hoy instalar depende de encontrar "Instalar" en el menú del navegador, que casi nadie encuentra.
 * El navegador avisa cuando la instalación es posible (`beforeinstallprompt`); se guarda ese aviso
 * y el botón lo dispara.
 */

interface EventoInstalacion extends Event {
  prompt(): Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

let aviso: EventoInstalacion | null = null

/**
 * Si el navegador ofrece instalar ahora mismo. El botón se esconde mientras esto sea falso: uno
 * que no puede cumplir lo que promete es peor que ninguno.
 */
export const puedeInstalar = ref(false)

/**
 * Se engancha al arrancar, antes de montar la aplicación: el navegador dispara el aviso muy
 * pronto y quien no lo escuche a tiempo se queda sin él para el resto de la sesión.
 */
export function escucharInstalacion(): void {
  window.addEventListener('beforeinstallprompt', (evento) => {
    // Sin esto el navegador muestra su propia franja, que compite con el botón del sistema.
    evento.preventDefault()
    aviso = evento as EventoInstalacion
    puedeInstalar.value = true
  })

  window.addEventListener('appinstalled', () => {
    aviso = null
    puedeInstalar.value = false
  })
}

/**
 * Abre el diálogo del navegador. El aviso guardado **sirve una sola vez**: acepte o no el usuario,
 * después hay que esperar a que el navegador vuelva a ofrecerlo.
 */
export async function instalar(): Promise<void> {
  if (aviso === null) return

  const evento = aviso
  aviso = null
  puedeInstalar.value = false

  await evento.prompt()
  await evento.userChoice
}
