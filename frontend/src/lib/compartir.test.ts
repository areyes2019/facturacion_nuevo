import { afterEach, describe, expect, it, vi } from 'vitest'
import { compartirArchivo, puedeCompartirArchivos } from './compartir'

/**
 * El compartir del escritorio (ver 007-facturacion.md): el PDF de una factura sale **solo**, sin
 * texto que lo acompañe, por el menú del sistema —en Windows 11, el catálogo de envío—.
 *
 * El alcance de Vitest en este proyecto es de módulos, sin jsdom ni componentes montados (ver
 * `vite.config.ts` y 011-precio-proveedor-utilidad.md), así que lo que se prueba aquí es la puerta
 * que decide: `puedeCompartirArchivos()` es lo que el botón consulta para mostrarse o no.
 */

const pdf = () => new Blob(['%PDF-1.4'], { type: 'application/pdf' })

/** Un navegador con el menú del sistema, que acepta lo que se le entregue. */
function conMenuDelSistema(share: (datos: unknown) => Promise<void>) {
  vi.stubGlobal('navigator', { share, canShare: () => true })
}

/** El enlace invisible que usa la descarga de respaldo, para poder mirarlo sin un DOM real. */
function conDescargaFalsa() {
  const enlace = { href: '', download: '', click: vi.fn() }

  vi.stubGlobal('document', { createElement: () => enlace })
  vi.stubGlobal('URL', { createObjectURL: () => 'blob:x', revokeObjectURL: () => {} })
  vi.stubGlobal('window', { open: vi.fn() })

  return enlace
}

afterEach(() => vi.unstubAllGlobals())

describe('compartirArchivo sin texto', () => {
  it('entrega el PDF solo, sin texto de acompañamiento', async () => {
    const share = vi.fn().mockResolvedValue(undefined)
    conMenuDelSistema(share)

    expect(await compartirArchivo(pdf(), 'factura-A123.pdf')).toBe('compartido')

    const datos = share.mock.calls[0][0] as { files: File[]; text?: string }
    expect(datos.text).toBeUndefined()
    expect(datos.files).toHaveLength(1)
    expect(datos.files[0].name).toBe('factura-A123.pdf')
    expect(datos.files[0].type).toBe('application/pdf')
  })

  // Cerrar el catálogo de envío sin elegir destino es una decisión del usuario, no una falla.
  it('cerrar el menú sin elegir destino no deja error ni descarga nada', async () => {
    conMenuDelSistema(() => Promise.reject(new DOMException('El usuario canceló', 'AbortError')))
    const enlace = conDescargaFalsa()

    expect(await compartirArchivo(pdf(), 'factura-A123.pdf')).toBe('cancelado')
    expect(enlace.click).not.toHaveBeenCalled()
  })

  // Sin canal elegido no hay a quién mandarlo: abrir WhatsApp sería inventarle un destino.
  it('sin menú del sistema solo descarga el PDF, sin abrir WhatsApp', async () => {
    vi.stubGlobal('navigator', {})
    const enlace = conDescargaFalsa()

    expect(puedeCompartirArchivos()).toBe(false)
    expect(await compartirArchivo(pdf(), 'factura-A123.pdf')).toBe('descargado')
    expect(enlace.download).toBe('factura-A123.pdf')
    expect(enlace.click).toHaveBeenCalledOnce()
    expect(window.open).not.toHaveBeenCalled()
  })
})
