/**
 * Compartir desde el navegador (ver 020-imagenes-articulos.md, 027-venta-mostrador-ticket.md y
 * 029-pwa-mostrador.md).
 *
 * La decisión entre "menú del sistema" y "abrir WhatsApp con el archivo descargado" se toma
 * preguntándole al navegador si **puede** compartir archivos, no por el ancho de la pantalla: en
 * escritorio muchos navegadores no tienen ese menú, y un botón que a veces hace algo y a veces no es
 * peor que dos comportamientos claros.
 *
 * **Quien llame a estas funciones tiene que traer los archivos ya descargados.** El menú del aparato
 * solo se abre mientras el gesto del usuario sigue vivo, y el navegador da unos pocos segundos:
 * esperar aquí a una descarga lo agota y el compartir se rechaza sin que nada lo explique (ver 029,
 * supuesto 78).
 */

/** Lo que ocurrió al compartir, para que la vista diga algo distinto en cada caso. */
export type ResultadoCompartir = 'compartido' | 'descargado' | 'copiado' | 'cancelado'

/** Un archivo listo para salir: ya descargado, con el nombre con el que llegará al cliente. */
export interface ArchivoCompartible {
  contenido: Blob
  nombre: string
}

export function puedeCompartirArchivos(): boolean {
  return typeof navigator.canShare === 'function' && typeof navigator.share === 'function'
}

/**
 * Comparte un archivo con su texto: el ticket de una venta (027) o el PDF de una cotización (029).
 */
export async function compartirArchivo(
  contenido: Blob,
  nombreArchivo: string,
  texto: string,
): Promise<ResultadoCompartir> {
  return compartirArchivos([{ contenido, nombre: nombreArchivo }], texto)
}

/**
 * Comparte varios archivos de una vez: el PDF y el XML de un CFDI, que sin su XML no le sirve al
 * contador del cliente (ver 029-pwa-mostrador.md).
 *
 * Si el aparato no admite el grupo completo se intenta **solo con el primero**, que es el que el
 * cliente mira, antes de caer a la descarga. Mandar algo es mejor que no mandar nada porque un
 * segundo archivo no cupo.
 */
export async function compartirArchivos(
  archivos: ArchivoCompartible[],
  texto: string,
): Promise<ResultadoCompartir> {
  const files = archivos.map(
    ({ contenido, nombre }) =>
      new File([contenido], nombre, { type: contenido.type || 'application/octet-stream' }),
  )

  if (puedeCompartirArchivos()) {
    for (const grupo of files.length > 1 ? [files, files.slice(0, 1)] : [files]) {
      if (!navigator.canShare({ files: grupo })) continue

      try {
        await navigator.share({ text: texto, files: grupo })

        return 'compartido'
      } catch (err) {
        // Cerrar el menú del sistema lanza AbortError y no es un fallo que reportar.
        if (err instanceof DOMException && err.name === 'AbortError') return 'cancelado'

        // Cualquier otro rechazo —un tipo de archivo que el navegador no acepta, un gesto que ya
        // caducó— no deja al usuario sin documento: se sigue por el camino de abajo, que también
        // termina en WhatsApp.
        break
      }
    }
  }

  archivos.forEach(({ contenido, nombre }) => descargar(contenido, nombre))
  await abrirWhatsappConTexto(texto)

  return 'descargado'
}

/**
 * El camino para cuando el menú del aparato no está: deja el mensaje en el portapapeles y abre
 * WhatsApp —Desktop si está instalado, Web si no— con el texto ya escrito. El botón prometía un
 * envío por WhatsApp, así que dejar el archivo en la carpeta de descargas y callarse no lo cumple.
 *
 * Copiar puede fallar sola —el navegador la rechaza si la ventana perdió el foco— y eso no puede
 * tumbar el envío: el mensaje va igual escrito en el enlace.
 */
async function abrirWhatsappConTexto(texto: string): Promise<void> {
  try {
    await copiarTexto(texto)
  } catch {
    // El texto viaja en la propia dirección de WhatsApp, así que no se pierde nada.
  }

  window.open(`https://wa.me/?text=${encodeURIComponent(texto)}`, '_blank', 'noopener')
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
