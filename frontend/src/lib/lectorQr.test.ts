import { describe, expect, it } from 'vitest'

import { documentoDeCodigoEtiqueta, hayDetectorDeCodigos, leerQr } from './lectorQr'

describe('leerQr', () => {
  it('devuelve null sin lanzar cuando el navegador no trae lector de códigos', async () => {
    // Safari y Firefox todavía no traen BarcodeDetector. Es un camino previsto —la imagen se sube
    // y el QR lo lee el backend—, no un error que deba romper el flujo.
    expect(hayDetectorDeCodigos()).toBe(false)
    await expect(leerQr(new Blob([]))).resolves.toBe(null)
  })
})

describe('documentoDeCodigoEtiqueta', () => {
  const origen = 'https://app.prosello.com.mx'

  it('acepta la etiqueta de un pedido y devuelve su tipo y número', () => {
    expect(documentoDeCodigoEtiqueta(`${origen}/pedidos/42/entregar`, origen)).toEqual({
      tipo: 'pedido',
      id: 42,
    })
  })

  it('acepta la etiqueta de una cotizacion y devuelve su tipo y número', () => {
    expect(documentoDeCodigoEtiqueta(`${origen}/cotizaciones/7/entregar`, origen)).toEqual({
      tipo: 'cotizacion',
      id: 7,
    })
  })

  it('tolera la diagonal final', () => {
    expect(documentoDeCodigoEtiqueta(`${origen}/pedidos/42/entregar/`, origen)).toEqual({
      tipo: 'pedido',
      id: 42,
    })
    expect(documentoDeCodigoEtiqueta(`${origen}/cotizaciones/7/entregar/`, origen)).toEqual({
      tipo: 'cotizacion',
      id: 7,
    })
  })

  it('rechaza un código de otro origen aunque calce con la ruta', () => {
    // Un QR pegado en cualquier caja podría imitar la dirección; el escáner no es un navegador.
    expect(documentoDeCodigoEtiqueta('https://otro-sitio.com/pedidos/42/entregar', origen)).toBe(
      null,
    )
    expect(
      documentoDeCodigoEtiqueta('https://otro-sitio.com/cotizaciones/7/entregar', origen),
    ).toBe(null)
  })

  it('rechaza otra pantalla del sistema', () => {
    expect(documentoDeCodigoEtiqueta(`${origen}/pedidos/42`, origen)).toBe(null)
    expect(documentoDeCodigoEtiqueta(`${origen}/pedidos/42/etiqueta`, origen)).toBe(null)
    expect(documentoDeCodigoEtiqueta(`${origen}/cotizaciones/7`, origen)).toBe(null)
    expect(documentoDeCodigoEtiqueta(`${origen}/cotizaciones/7/etiqueta`, origen)).toBe(null)
  })

  it('rechaza lo que ni siquiera es una dirección', () => {
    expect(documentoDeCodigoEtiqueta('PED-00042', origen)).toBe(null)
  })
})
