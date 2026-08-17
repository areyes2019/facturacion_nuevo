import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { describe, expect, it } from 'vitest'

import { ajusteAlPeso, calcularTotales, prorratear, type LineaCalculable } from './totalesDocumento'
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
  redondear_al_peso: boolean
  esperado: {
    subtotal: number
    total_descuento: number
    total_iva_16: number
    total_iva_0: number
    total_exento: number
    ajuste_al_peso: number
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
      caso.redondear_al_peso,
    )

    expect(totales.subtotal).toBe(caso.esperado.subtotal)
    expect(totales.total_descuento).toBe(caso.esperado.total_descuento)
    expect(totales.total_iva_16).toBe(caso.esperado.total_iva_16)
    expect(totales.total_iva_0).toBe(caso.esperado.total_iva_0)
    expect(totales.total_exento).toBe(caso.esperado.total_exento)
    expect(totales.ajuste_al_peso).toBe(caso.esperado.ajuste_al_peso)
    expect(totales.total).toBe(caso.esperado.total)

    expect(totales.lineas).toHaveLength(caso.esperado.lineas.length)
    caso.esperado.lineas.forEach((lineaEsperada, i) => {
      expect(totales.lineas[i].importe).toBe(lineaEsperada.importe)
      expect(totales.lineas[i].iva_importe).toBe(lineaEsperada.iva_importe)
    })
  })
})

/**
 * Barrido de specs/030-total-al-peso-cerrado.md, espejo del de PHPUnit: con un artículo cuyo precio
 * con IVA es un peso cerrado, cualquier cantidad hasta 190 piezas da un total que es exactamente el
 * múltiplo de ese precio.
 */
describe('ajuste al peso cerrado', () => {
  it('el total ajustado es el multiplo exacto del precio con IVA hasta 190 piezas', () => {
    const precioSinIva = 175.86
    const precioConIva = 204.0

    for (let cantidad = 1; cantidad <= 190; cantidad++) {
      const totales = calcularTotales(
        [
          {
            cantidad,
            precio_unitario: precioSinIva,
            descuento_tipo: null,
            descuento_valor: null,
            tasa_iva: '16',
          },
        ],
        null,
        null,
        true,
      )

      expect(totales.total).toBe(Math.round(cantidad * precioConIva * 100) / 100)
      expect(totales.ajuste_al_peso).toBeGreaterThanOrEqual(0)
      expect(totales.ajuste_al_peso).toBeLessThan(1)
    }
  })

  it('nunca baja el total ni pasa de un peso', () => {
    for (let centavos = 0; centavos <= 200000; centavos++) {
      const total = Math.round(centavos) / 100
      const ajuste = ajusteAlPeso(total)

      expect(ajuste).toBeGreaterThanOrEqual(0)
      expect(ajuste).toBeLessThan(1)

      const centavosFinales = Math.round((total + ajuste) * 100) % 100

      expect(centavosFinales === 0 || centavosFinales <= 5).toBe(true)
    }
  })

  it('sin pedirlo, el total no se mueve', () => {
    const linea: LineaCalculable = {
      cantidad: 3,
      precio_unitario: 175.86,
      descuento_tipo: null,
      descuento_valor: null,
      tasa_iva: '16',
    }

    expect(calcularTotales([linea], null, null).total).toBe(611.99)
    expect(calcularTotales([linea], null, null).ajuste_al_peso).toBe(0)
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
