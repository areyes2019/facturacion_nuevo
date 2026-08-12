/**
 * Lectura de la Constancia de Situación Fiscal en el navegador (ver
 * specs/016-constancia-situacion-fiscal-qr.md).
 *
 * Toda la lógica vive aquí y no dentro del componente para que Vitest la pueda probar. Las dos
 * librerías pesadas —`pdfjs-dist` y `tesseract.js`— se importan de forma diferida: quien nunca
 * sube una constancia no descarga un byte extra, y quien sube un PDF legible nunca descarga el
 * reconocimiento de caracteres.
 */

/** Etiquetas de la constancia, con las mismas variantes que reconoce el backend. */
const ALIAS: Record<string, string[]> = {
  rfc: ['rfc', 'elrfc'],
  razon_social: [
    'denominacionorazonsocial',
    'denominacionrazonsocial',
    'razonsocial',
    'denominacionsocial',
    'denominacion',
    'nombredelcontribuyente',
  ],
  nombre: ['nombres', 'nombre'],
  apellido_paterno: ['primerapellido', 'apellidopaterno'],
  apellido_materno: ['segundoapellido', 'apellidomaterno'],
  regimen: ['regimen', 'regimenfiscal', 'regimenes', 'regimenesfiscales'],
  codigo_postal: ['codigopostal', 'cp'],
  vialidad: ['nombredevialidad', 'nombredelavialidad', 'vialidad', 'calle'],
  numero_exterior: ['numeroexterior', 'numexterior', 'noexterior'],
  numero_interior: ['numerointerior', 'numinterior', 'nointerior'],
  colonia: ['nombredelacolonia', 'colonia'],
  municipio: [
    'nombredelmunicipioodemarcacionterritorial',
    'municipioodelegacion',
    'municipio',
    'demarcacionterritorial',
  ],
  estado: ['nombredelaentidadfederativa', 'entidadfederativa', 'estado'],
}

export interface CamposConstancia {
  rfc: string | null
  razon_social: string | null
  regimen_fiscal: string | null
  codigo_postal_fiscal: string | null
  direccion_comercial: string | null
}

/**
 * De dónde salieron los datos, y por lo tanto cuánto hay que desconfiar de ellos:
 *
 * - `SAT_QR_DIRECT`: el SAT respondió en vivo. Oficial y al día.
 * - `PDF_TEXTO`: copiado carácter por carácter del PDF. Exacto, pero es lo que decía el papel.
 * - `OCR_LOCAL`: adivinado a partir de píxeles. Puede traer errores que no se ven.
 */
export type FuenteConstancia = 'SAT_QR_DIRECT' | 'PDF_TEXTO' | 'OCR_LOCAL'

export interface RegimenDisponible {
  id: string
  texto: string
}

export interface ResultadoConstancia {
  fuente: FuenteConstancia
  campos: CamposConstancia
  regimenesDisponibles: RegimenDisponible[]
  advertencias: string[]
  clienteExistente: { id: number; razon_social: string } | null
}

/**
 * Etiqueta reducida a su esqueleto comparable: sin acentos, sin mayúsculas y sin puntuación. Es la
 * misma normalización que hace `MapeadorCampos::normalizarEtiqueta` en el backend, de modo que las
 * dos implementaciones reconocen exactamente las mismas etiquetas.
 */
export function normalizarEtiqueta(etiqueta: string): string {
  return etiqueta
    .trim()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]/g, '')
}

/**
 * Pares "etiqueta: valor" de un texto plano. Los espacios alrededor de los dos puntos son
 * horizontales a propósito: si se admitiera el salto de línea, una etiqueta sin valor se quedaría
 * con el contenido del renglón siguiente.
 */
export function extraerPares(texto: string): Record<string, string> {
  const pares: Record<string, string> = {}
  const patron = /^[ \t\u00a0]*([^\r\n:]{3,80}?)[ \t]*:[ \t]*([^\r\n]{1,120}?)[ \t\u00a0]*$/gm

  for (const [, etiqueta, valor] of texto.matchAll(patron)) {
    const clave = normalizarEtiqueta(etiqueta)

    if (clave !== '' && !(clave in pares)) {
      pares[clave] = valor.trim()
    }
  }

  return pares
}

function buscar(pares: Record<string, string>, campo: keyof typeof ALIAS): string | null {
  for (const alias of ALIAS[campo]) {
    const valor = pares[alias]?.trim()

    if (valor) {
      return valor
    }
  }

  return null
}

/**
 * Campos fiscales a partir del texto de una constancia.
 *
 * A diferencia del backend, aquí **no** se valida contra los catálogos del SAT: el navegador no
 * los tiene. El régimen y el código postal llegan al formulario como propuesta y sus propios
 * componentes —`RegimenFiscalSelect` y `CodigoPostalCombobox`— se encargan de que solo se pueda
 * guardar un valor del catálogo.
 */
export function extraerCamposDeTexto(texto: string): CamposConstancia {
  const pares = extraerPares(texto)

  const rfcCrudo = buscar(pares, 'rfc')
  const rfc = rfcCrudo ? rfcCrudo.toUpperCase().replace(/[^A-ZÑ&0-9]/g, '') : null

  const nombreCompuesto = [
    buscar(pares, 'nombre'),
    buscar(pares, 'apellido_paterno'),
    buscar(pares, 'apellido_materno'),
  ]
    .filter(Boolean)
    .join(' ')

  const cp = buscar(pares, 'codigo_postal')?.replace(/\D/g, '') ?? null

  return {
    rfc: rfc && rfc.length >= 12 ? rfc : null,
    razon_social: buscar(pares, 'razon_social') ?? (nombreCompuesto || null),
    regimen_fiscal: buscar(pares, 'regimen')?.match(/\b\d{3}\b/)?.[0] ?? null,
    codigo_postal_fiscal: cp?.length === 5 ? cp : null,
    direccion_comercial: armarDireccion(pares),
  }
}

/**
 * El domicilio del SAT viene desarmado en seis campos y el sistema lo guarda en una sola línea
 * (`direccion_comercial`, ver spec 004). Misma regla que en el backend, para que un usuario no vea
 * una dirección distinta según de qué camino vinieron sus datos.
 */
function armarDireccion(pares: Record<string, string>): string | null {
  const interior = buscar(pares, 'numero_interior')

  const calle = [
    buscar(pares, 'vialidad'),
    buscar(pares, 'numero_exterior'),
    interior ? `INT ${interior}` : null,
  ]
    .filter(Boolean)
    .join(' ')

  const colonia = buscar(pares, 'colonia')

  const partes = [
    calle || null,
    colonia ? `COL ${colonia}` : null,
    buscar(pares, 'municipio'),
    buscar(pares, 'estado'),
  ].filter(Boolean)

  return partes.length === 0 ? null : partes.join(', ').slice(0, 255)
}

/**
 * Dibuja la primera página de un PDF como imagen. Un PDF no es una foto sino instrucciones de
 * dibujo, así que para poder mirar el código QR hay que rasterizarlo primero.
 *
 * Se hace en el navegador y no en el servidor a propósito: la alternativa exigía instalar
 * ImageMagick y Ghostscript en desarrollo y en producción (ver spec 016).
 */
export async function renderizarPrimeraPagina(archivo: File): Promise<Blob> {
  const pdfjs = await import('pdfjs-dist')

  pdfjs.GlobalWorkerOptions.workerSrc = (
    await import('pdfjs-dist/build/pdf.worker.min.mjs?url')
  ).default

  const documento = await pdfjs.getDocument({ data: await archivo.arrayBuffer() }).promise
  const pagina = await documento.getPage(1)

  // Se dibuja al doble de tamaño: un QR de constancia ocupa una esquina pequeña de la hoja y a
  // escala 1 quedan demasiado pocos píxeles por módulo para que el lector lo distinga.
  const viewport = pagina.getViewport({ scale: 2 })
  const canvas = document.createElement('canvas')
  canvas.width = viewport.width
  canvas.height = viewport.height

  const contexto = canvas.getContext('2d')

  if (!contexto) {
    throw new Error('El navegador no pudo preparar el lienzo para dibujar el PDF.')
  }

  await pagina.render({ canvas, canvasContext: contexto, viewport }).promise

  return await new Promise<Blob>((resolve, reject) => {
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error('No se pudo convertir la página.'))),
      'image/png',
    )
  })
}

interface DetectorCodigos {
  detect(fuente: ImageBitmapSource): Promise<{ rawValue: string }[]>
}

/**
 * Lee el código QR con el detector nativo del dispositivo: el mismo que usa la cámara del celular
 * al apuntar a un código, y muy superior a cualquier librería con fotos inclinadas, con sombra o
 * movidas.
 *
 * Devuelve `null` sin lanzar cuando el navegador no lo trae (Safari y Firefox todavía no) o cuando
 * no encuentra ningún código: en ambos casos el flujo sigue subiendo la imagen para que el QR lo
 * lea el backend. Un lector ausente es un camino previsto, no un error.
 */
export async function leerQr(imagen: Blob): Promise<string | null> {
  const constructor = (
    globalThis as unknown as {
      BarcodeDetector?: new (opciones: { formats: string[] }) => DetectorCodigos
    }
  ).BarcodeDetector

  if (!constructor) {
    return null
  }

  try {
    const bitmap = await createImageBitmap(imagen)
    const codigos = await new constructor({ formats: ['qr_code'] }).detect(bitmap)
    bitmap.close()

    return codigos[0]?.rawValue?.trim() || null
  } catch {
    return null
  }
}

/** Reconocimiento de caracteres, el último recurso: puede equivocarse y por eso se avisa. */
export async function reconocerTexto(imagen: Blob): Promise<string> {
  const { recognize } = await import('tesseract.js')
  const resultado = await recognize(imagen, 'spa')

  return resultado.data.text
}
