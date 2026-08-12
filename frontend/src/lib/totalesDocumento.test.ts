import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { describe, expect, it } from 'vitest'

import { calcularTotales, prorratear, type LineaCalculable } from './totalesDocumento'
import type { TipoDescuento } from '../stores/facturas'

/**
 * Casos del cálculo de totales, leídos del fixture compartido con el backend (ver
 * specs/012-ordenes-compra.md, adición técnica 42). El mismo archivo lo consume PHPUnit sobre
 * FacturaTotalesCalculator, de modo que cambiar una implementación sin la otra rompe la suite del
 * lado no tocado.
 */
interface CasoDelFixture {
  descripcion: string
  lineas: LineaCalculable[]
  descuento_global_tipo: TipoDescuento | null
  descuento_global_valor: number | null
  esperado: {
    subtotal: number
    total_descuento: number
    total_iva_16: number
    total_iva_0: number
    total_exento: number
    total: number
    lineas: { importe: number; iva_importe: number }[]
  }
}

const rutaFixture = fileURLToPath(
  new URL('../../../shared/fixtures/totales-documento.json', import.meta.url),
)
const casos: CasoDelFixture[] = JSON.parse(readFileSync(rutaFixture, 'utf-8')).casos

describe('totales del documento contra el fixture compartido', () => {
  it('el fixture trae casos', () => {
    expect(casos.length).toBeGreaterThan(0)
  })

  it.each(casos)('$descripcion', (caso) => {
    const totales = calcularTotales(
      caso.lineas,
      caso.descuento_global_tipo,
      caso.descuento_global_valor,
    )

    expect(totales.subtotal).toBe(caso.esperado.subtotal)
    expect(totales.total_descuento).toBe(caso.esperado.total_descuento)
    expect(totales.total_iva_16).toBe(caso.esperado.total_iva_16)
    expect(totales.total_iva_0).toBe(caso.esperado.total_iva_0)
    expect(totales.total_exento).toBe(caso.esperado.total_exento)
    expect(totales.total).toBe(caso.esperado.total)

    expect(totales.lineas).toHaveLength(caso.esperado.lineas.length)
    caso.esperado.lineas.forEach((lineaEsperada, i) => {
      expect(totales.lineas[i].importe).toBe(lineaEsperada.importe)
      expect(totales.lineas[i].iva_importe).toBe(lineaEsperada.iva_importe)
    })
  })
})

describe('prorrateo', () => {
  it('la ultima linea absorbe el residuo para que la suma sea exacta', () => {
    const partes = prorratear([100, 100, 100], 300, 100)

    expect(partes).toEqual([33.33, 33.33, 33.34])
    expect(partes.reduce((suma, parte) => suma + parte, 0)).toBeCloseTo(100, 10)
  })

  it('sin descuento global todas las partes son cero', () => {
    expect(prorratear([100, 200], 300, 0)).toEqual([0, 0])
  })

  it('un documento sin lineas no revienta', () => {
    expect(prorratear([], 0, 50)).toEqual([])
  })
})
