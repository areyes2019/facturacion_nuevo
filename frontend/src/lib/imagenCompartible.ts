/**
 * Deja una imagen del catálogo lista para el menú de compartir del aparato.
 *
 * El archivo guardado en el servidor es WEBP, y **WhatsApp trata los `.webp` como calcomanías**:
 * compartir el original le llegaría al cliente como sticker en vez de como la foto del producto.
 * Convertir aquí, en el navegador, evita guardar una segunda copia de cada imagen en el servidor
 * solo para este caso (ver 020-imagenes-articulos.md).
 *
 * Vive en su propio módulo porque lo usan dos pantallas —la ficha del escritorio y la del catálogo
 * del mostrador (ver 031-mostrador-consulta.md)—, y una copia se habría quedado atrás la primera
 * vez que se corrigiera. Mismo criterio con el que `leerQr()` se mudó a `lib/lectorQr.ts`.
 */
export async function comoJpeg(blob: Blob): Promise<Blob> {
  const bitmap = await createImageBitmap(blob)
  const canvas = document.createElement('canvas')
  canvas.width = bitmap.width
  canvas.height = bitmap.height

  const contexto = canvas.getContext('2d')
  if (!contexto) throw new Error('sin canvas')

  // Fondo blanco: un WEBP con transparencia quedaría con el fondo negro al pasar a JPEG, que no
  // tiene canal alfa.
  contexto.fillStyle = '#ffffff'
  contexto.fillRect(0, 0, canvas.width, canvas.height)
  contexto.drawImage(bitmap, 0, 0)
  bitmap.close()

  return await new Promise<Blob>((resolve, reject) => {
    canvas.toBlob(
      (resultado) => (resultado ? resolve(resultado) : reject(new Error('sin blob'))),
      'image/jpeg',
      0.9,
    )
  })
}
