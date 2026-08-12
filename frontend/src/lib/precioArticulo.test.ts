import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { describe, expect, it } from 'vitest'

import { calcularCadena, precioVentaSinIva, techo2 } from './precioArticulo'

/**
 * Casos frontera de la cadena de precios, leídos del fixture compartido con el backend (ver
 * specs/011-precio-proveedor-utilidad.md). El mismo archivo lo consume PHPUnit sobre
 * PrecioArticuloCalculator, de modo que cambiar una implementación sin la otra rompe la suite del
 * lado no tocado.
 */
interface CasoDelFixture {
  descripcion: string
  precio_proveedor: number
  descuento: number
  utilidad_porcentaje: number
  costo_goma: number
  costo_con_descuento: number
  costo_total: number
  precio_unitario_sin_iva: number
  utilidad: number
}

const rutaFixture = fileURLToPath(
  new URL('../../../shared/fixtures/precios-articulos.json', import.meta.url),
)
const casos: CasoDelFixture[] = JSON.parse(readFileSync(rutaFixture, 'utf-8')).casos

describe('cadena de precios contra el fixture compartido', () => {
  it('el fixture trae casos', () => {
    expect(casos.length).toBeGreaterThan(0)
  })

  it.each(casos)('$descripcion', (caso) => {
    const cadena = calcularCadena(
      caso.precio_proveedor,
      caso.descuento,
      caso.utilidad_porcentaje,
      caso.costo_goma,
    )

    expect(cadena.costo_con_descuento).toBe(caso.costo_con_descuento)
    expect(cadena.costo_total).toBe(caso.costo_total)
    expect(cadena.precio_unitario_sin_iva).toBe(caso.precio_unitario_sin_iva)
    expect(cadena.utilidad).toBe(caso.utilidad)
  })

  it('sin goma la cadena queda idéntica a la de 011', () => {
    // Con costo_goma en 0 la fórmula nueva y la vieja son la misma operación. Omitir el argumento
    // debe dar exactamente lo mismo que pasar 0 (ver specs/014-costo-elaboracion-goma.md).
    for (const caso of casos.filter((c) => c.costo_goma === 0)) {
      const sinArgumento = calcularCadena(
        caso.precio_proveedor,
        caso.descuento,
        caso.utilidad_porcentaje,
      )

      expect(sinArgumento.costo_total).toBe(caso.costo_con_descuento)
      expect(sinArgumento.precio_unitario_sin_iva).toBe(caso.precio_unitario_sin_iva)
      expect(sinArgumento.utilidad).toBe(caso.utilidad)
    }
  })
})

describe('techo2', () => {
  it('redondea despues de escalar a centavos', () => {
    // La variante que redondea antes de escalar devuelve 16.18 aquí, porque 0.07 * 100 da
    // 7.000000000000001 (ver 011).
    expect(techo2(16.17)).toBe(16.17)
    expect(techo2(0.07)).toBe(0.07)
  })

  it('sube cuando hay fraccion de centavo real', () => {
    expect(techo2(133.0133)).toBe(133.02)
  })

  it('no altera un valor exacto', () => {
    expect(techo2(273)).toBe(273)
    expect(techo2(218.75)).toBe(218.75)
  })
})

describe('precioVentaSinIva', () => {
  it('nunca deja el precio por debajo del markup solicitado', () => {
    for (const porcentajeBp of [500, 1000, 1250, 3000, 3333, 5500, 9999]) {
      for (let costoCentavos = 100; costoCentavos <= 20000; costoCentavos += 7) {
        const exacto = costoCentavos * (10000 + porcentajeBp)
        const esperado = Math.floor(exacto / 10000) + (exacto % 10000 ? 1 : 0)
        const obtenido = Math.round(
          precioVentaSinIva(costoCentavos / 100, porcentajeBp / 100) * 100,
        )

        expect(obtenido, `costo ${costoCentavos / 100} con markup ${porcentajeBp / 100}%`).toBe(
          esperado,
        )
      }
    }
  })
})
