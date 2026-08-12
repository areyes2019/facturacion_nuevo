import { describe, expect, it } from 'vitest'

import { extraerCamposDeTexto, extraerPares, leerQr, normalizarEtiqueta } from './constanciaFiscal'

/**
 * Lectura de la constancia en el navegador (ver specs/016-constancia-situacion-fiscal-qr.md).
 *
 * Se prueba la extracción de campos, que es la parte que corre cuando el SAT está caído y la
 * constancia es una imagen: justo el camino donde un error pasa inadvertido.
 */

const TEXTO_MORAL = `CONSTANCIA DE SITUACION FISCAL
Datos de identificación del contribuyente
RFC: PME120315AB9
Denominación o Razón Social: PANDA CONNECT LOGISTICS SA DE CV
Régimen: 601 - General de Ley Personas Morales
Datos del domicilio registrado
Código Postal: 38000
Nombre de Vialidad: AV TECNOLOGICO
Número Exterior: 105
Número Interior:
Nombre de la Colonia: INDUSTRIAL
Nombre del Municipio o Demarcación Territorial: CELAYA
Nombre de la Entidad Federativa: GUANAJUATO`

const TEXTO_FISICA = `RFC: GOMR850712QX1
Nombre (s): ROSA MARIA
Primer Apellido: GOMEZ
Segundo Apellido: MARTINEZ
Régimen: 612 - Personas Físicas con Actividades Empresariales y Profesionales
Código Postal: 06600
Nombre de Vialidad: CALLE ORIZABA
Número Exterior: 87
Número Interior: 3
Nombre de la Colonia: ROMA NORTE
Nombre del Municipio o Demarcación Territorial: CUAUHTEMOC
Nombre de la Entidad Federativa: CIUDAD DE MEXICO`

describe('normalizarEtiqueta', () => {
  it('reduce la etiqueta a su esqueleto comparable', () => {
    expect(normalizarEtiqueta('Denominación o Razón Social:')).toBe('denominacionorazonsocial')
    expect(normalizarEtiqueta('  NÚMERO EXTERIOR  ')).toBe('numeroexterior')
  })

  it('reconoce la misma etiqueta escrita de varias formas', () => {
    expect(normalizarEtiqueta('Código Postal')).toBe(normalizarEtiqueta('CODIGO POSTAL:'))
  })
})

describe('extraerPares', () => {
  it('una etiqueta sin valor no se queda con el contenido del renglón siguiente', () => {
    const pares = extraerPares('Número Interior:\nNombre de la Colonia: INDUSTRIAL')

    expect(pares.numerointerior).toBeUndefined()
    expect(pares.nombredelacolonia).toBe('INDUSTRIAL')
  })

  it('conserva la primera aparición de una etiqueta repetida', () => {
    expect(extraerPares('RFC: UNO\nRFC: DOS').rfc).toBe('UNO')
  })
})

describe('extraerCamposDeTexto', () => {
  it('extrae los datos de una constancia de persona moral', () => {
    expect(extraerCamposDeTexto(TEXTO_MORAL)).toEqual({
      rfc: 'PME120315AB9',
      razon_social: 'PANDA CONNECT LOGISTICS SA DE CV',
      regimen_fiscal: '601',
      codigo_postal_fiscal: '38000',
      direccion_comercial: 'AV TECNOLOGICO 105, COL INDUSTRIAL, CELAYA, GUANAJUATO',
    })
  })

  it('une el nombre con sus apellidos en una constancia de persona física', () => {
    const campos = extraerCamposDeTexto(TEXTO_FISICA)

    expect(campos.razon_social).toBe('ROSA MARIA GOMEZ MARTINEZ')
    expect(campos.direccion_comercial).toBe(
      'CALLE ORIZABA 87 INT 3, COL ROMA NORTE, CUAUHTEMOC, CIUDAD DE MEXICO',
    )
  })

  it('produce la misma dirección de una línea que arma el backend', () => {
    // Las dos implementaciones tienen que coincidir: si no, el usuario vería una dirección
    // distinta según de qué camino vinieron sus datos.
    expect(extraerCamposDeTexto(TEXTO_MORAL).direccion_comercial).toBe(
      'AV TECNOLOGICO 105, COL INDUSTRIAL, CELAYA, GUANAJUATO',
    )
  })

  it('devuelve los campos que no encontró en null, sin inventarlos', () => {
    expect(extraerCamposDeTexto('Documento sin nada útil')).toEqual({
      rfc: null,
      razon_social: null,
      regimen_fiscal: null,
      codigo_postal_fiscal: null,
      direccion_comercial: null,
    })
  })

  it('descarta un código postal que no tiene cinco dígitos', () => {
    expect(extraerCamposDeTexto('RFC: PME120315AB9\nCódigo Postal: 38').codigo_postal_fiscal).toBe(
      null,
    )
  })

  it('descarta un rfc demasiado corto para serlo', () => {
    expect(extraerCamposDeTexto('RFC: PME12').rfc).toBe(null)
  })
})

describe('leerQr', () => {
  it('devuelve null sin lanzar cuando el navegador no trae lector de códigos', async () => {
    // Safari y Firefox todavía no traen BarcodeDetector. Es un camino previsto —la imagen se sube
    // y el QR lo lee el backend—, no un error que deba romper el flujo.
    expect('BarcodeDetector' in globalThis).toBe(false)
    await expect(leerQr(new Blob([]))).resolves.toBe(null)
  })
})
