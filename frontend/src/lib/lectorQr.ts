/**
 * Lectura de códigos QR con el detector nativo del navegador (ver 029-pwa-mostrador.md).
 *
 * Vivía dentro de `constanciaFiscal.ts`, que durante un tiempo fue su único consumidor. Con el
 * escáner de etiquetas del modo mostrador son dos las pantallas que leen el mismo tipo de código con
 * el mismo detector, así que se extrajo: una copia se habría quedado atrás la primera vez que se
 * corrigiera un caso.
 *
 * El detector es el mismo que usa la cámara del celular al apuntar a un código, y muy superior a
 * cualquier librería con fotos inclinadas, con sombra o movidas. Cuando el navegador no lo trae
 * —Safari y Firefox todavía no— nada de aquí lanza: devolver `null` es un camino previsto y cada
 * consumidor decide qué ofrecer en su lugar.
 */

interface DetectorCodigos {
  detect(fuente: ImageBitmapSource): Promise<{ rawValue: string }[]>
}

type ConstructorDetector = new (opciones: { formats: string[] }) => DetectorCodigos

/**
 * Lector reutilizable: se construye una vez y se le pasan cuadros de video uno tras otro. El
 * escáner lee varias veces por segundo y construir un detector por cuadro es trabajo tirado.
 */
export interface LectorQr {
  leer(fuente: ImageBitmapSource): Promise<string | null>
}

function constructorDetector(): ConstructorDetector | undefined {
  return (globalThis as unknown as { BarcodeDetector?: ConstructorDetector }).BarcodeDetector
}

/** Si el navegador trae detector de códigos. Falso obliga a ofrecer otro camino, no a fallar. */
export function hayDetectorDeCodigos(): boolean {
  return constructorDetector() !== undefined
}

export function crearLectorQr(): LectorQr | null {
  const constructor = constructorDetector()

  if (!constructor) {
    return null
  }

  const detector = new constructor({ formats: ['qr_code'] })

  return {
    async leer(fuente: ImageBitmapSource): Promise<string | null> {
      try {
        const codigos = await detector.detect(fuente)

        return codigos[0]?.rawValue?.trim() || null
      } catch {
        // Un cuadro de video que el detector no pudo procesar no es un error: viene otro enseguida.
        return null
      }
    },
  }
}

/**
 * Lee el código QR de una imagen suelta.
 *
 * Devuelve `null` sin lanzar cuando el navegador no trae el detector o cuando no encuentra ningún
 * código. En la constancia fiscal el flujo sigue subiendo la imagen para que el QR lo lea el
 * backend (ver 016-constancia-situacion-fiscal-qr.md).
 */
export async function leerQr(imagen: Blob): Promise<string | null> {
  const lector = crearLectorQr()

  if (!lector) {
    return null
  }

  try {
    const bitmap = await createImageBitmap(imagen)
    const codigo = await lector.leer(bitmap)
    bitmap.close()

    return codigo
  } catch {
    return null
  }
}

/** La forma que escribe `Pedido::urlEntrega()` en el QR de la etiqueta y del ticket. */
const RUTA_ENTREGA = /^\/pedidos\/(\d+)\/entregar\/?$/

/**
 * Número de pedido que codifica una etiqueta del sistema, o `null` si el código es ajeno.
 *
 * Se exige **el mismo origen que la aplicación**: un QR pegado en cualquier caja podría llevar a
 * donde sea, y el escáner de un punto de venta no es un navegador. Con el id en la mano, el escáner
 * navega dentro de la aplicación y nunca abre una dirección de afuera.
 */
export function pedidoDeCodigoEtiqueta(codigo: string, origen: string): number | null {
  let url: URL

  try {
    url = new URL(codigo)
  } catch {
    return null
  }

  if (url.origin !== origen) {
    return null
  }

  const id = RUTA_ENTREGA.exec(url.pathname)?.[1]

  return id === undefined ? null : Number(id)
}

/**
 * Zumbido corto al reconocer un código válido: la confirmación que no obliga a mirar la pantalla,
 * con un paquete en la otra mano. Donde el aparato no vibra, simplemente no pasa nada.
 */
export function vibrarLectura(): void {
  if (typeof navigator.vibrate === 'function') {
    navigator.vibrate(60)
  }
}
