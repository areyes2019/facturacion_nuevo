import { describe, expect, it } from 'vitest'

import { hayDetectorDeCodigos, leerQr, pedidoDeCodigoEtiqueta } from './lectorQr'

describe('leerQr', () => {
  it('devuelve null sin lanzar cuando el navegador no trae lector de códigos', async () => {
    // Safari y Firefox todavía no traen BarcodeDetector. Es un camino previsto —la imagen se sube
    // y el QR lo lee el backend—, no un error que deba romper el flujo.
    expect(hayDetectorDeCodigos()).toBe(false)
    await expect(leerQr(new Blob([]))).resolves.toBe(null)
  })
})

describe('pedidoDeCodigoEtiqueta', () => {
  const origen = 'https://app.prosello.com.mx'

  it('acepta la etiqueta del sistema y devuelve el número de pedido', () => {
    expect(pedidoDeCodigoEtiqueta(`${origen}/pedidos/42/entregar`, origen)).toBe(42)
  })

  it('tolera la diagonal final', () => {
    expect(pedidoDeCodigoEtiqueta(`${origen}/pedidos/42/entregar/`, origen)).toBe(42)
  })

  it('rechaza un código de otro origen aunque calce con la ruta', () => {
    // Un QR pegado en cualquier caja podría imitar la dirección; el escáner no es un navegador.
    expect(pedidoDeCodigoEtiqueta('https://otro-sitio.com/pedidos/42/entregar', origen)).toBe(null)
  })

  it('rechaza otra pantalla del sistema', () => {
    expect(pedidoDeCodigoEtiqueta(`${origen}/pedidos/42`, origen)).toBe(null)
    expect(pedidoDeCodigoEtiqueta(`${origen}/pedidos/42/etiqueta`, origen)).toBe(null)
  })

  it('rechaza lo que ni siquiera es una dirección', () => {
    expect(pedidoDeCodigoEtiqueta('PED-00042', origen)).toBe(null)
  })
})
