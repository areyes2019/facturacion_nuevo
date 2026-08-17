/**
 * Compartir desde el navegador (ver 020-imagenes-articulos.md y 027-venta-mostrador-ticket.md).
 *
 * La decisión entre "menú del sistema" y "portapapeles" se toma preguntándole al navegador si
 * **puede** compartir archivos, no por el ancho de la pantalla: en escritorio muchos navegadores no
 * tienen ese menú, y un botón que a veces hace algo y a veces no es peor que dos comportamientos
 * claros.
 */

/** Lo que ocurrió al compartir, para que la vista diga algo distinto en cada caso. */
export type ResultadoCompartir = 'compartido' | 'descargado' | 'copiado' | 'cancelado'

export function puedeCompartirArchivos(): boolean {
  return typeof navigator.canShare === 'function' && typeof navigator.share === 'function'
}

/**
 * Comparte un archivo con su texto: el ticket de una venta (027) o el PDF de una cotización (029).
 *
 * - **Celular**: menú de compartir del propio aparato, con archivo y texto. Es el único camino por
 *   el que un archivo puede salir hacia WhatsApp desde una página web.
 * - **Escritorio**: descarga el archivo y copia el texto, para arrastrarlo a WhatsApp Desktop y
 *   pegar el mensaje. A diferencia de la ficha de artículo, aquí **sí** se descarga: el archivo no
 *   existe en ningún otro lado y sin él el botón no serviría de nada en el mostrador.
 */
export async function compartirArchivo(
  contenido: Blob,
  nombreArchivo: string,
  texto: string,
): Promise<ResultadoCompartir> {
  const archivo = new File([contenido], nombreArchivo, {
    type: contenido.type || 'application/octet-stream',
  })

  if (puedeCompartirArchivos() && navigator.canShare({ files: [archivo] })) {
    try {
      await navigator.share({ text: texto, files: [archivo] })

      return 'compartido'
    } catch (err) {
      // Cerrar el menú del sistema lanza AbortError y no es un fallo que reportar.
      if (err instanceof DOMException && err.name === 'AbortError') return 'cancelado'

      throw err
    }
  }

  descargar(contenido, nombreArchivo)
  await copiarTexto(texto)

  return 'descargado'
}

/**
 * Comparte texto suelto: el enlace de autofactura y el aviso de "ya está listo".
 *
 * En celular usa el menú del sistema. En escritorio abre `wa.me`, que Windows enruta a WhatsApp
 * Desktop si está instalado y a WhatsApp Web si no, con el mensaje ya escrito: el usuario solo
 * elige el contacto.
 */
export async function compartirTexto(texto: string): Promise<ResultadoCompartir> {
  if (typeof navigator.share === 'function') {
    try {
      await navigator.share({ text: texto })

      return 'compartido'
    } catch (err) {
      if (err instanceof DOMException && err.name === 'AbortError') return 'cancelado'
      // Si el menú falla por cualquier otra razón se sigue por WhatsApp, que siempre está.
    }
  }

  window.open(`https://wa.me/?text=${encodeURIComponent(texto)}`, '_blank', 'noopener')

  return 'compartido'
}

export async function copiarTexto(texto: string): Promise<void> {
  await navigator.clipboard.writeText(texto)
}

function descargar(contenido: Blob, nombreArchivo: string): void {
  const url = URL.createObjectURL(contenido)
  const enlace = document.createElement('a')
  enlace.href = url
  enlace.download = nombreArchivo
  enlace.click()
  URL.revokeObjectURL(url)
}
