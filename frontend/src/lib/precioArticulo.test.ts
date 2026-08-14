import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { describe, expect, it } from 'vitest'

import {
  calcularCadena,
  factorIva,
  precioConIva,
  precioVentaSinIva,
  redondearAPesoEntero,
  redondeo2,
  techo2,
} from './precioArticulo'

/**
 * Casos frontera de la cadena de precios, leídos del fixture compartido con el backend (ver
 * specs/011-precio-proveedor-utilidad.md y specs/024-precios-sin-centavos.md). El mismo archivo lo
 * consume PHPUnit sobre PrecioArticuloCalculator, de modo que cambiar una implementación sin la otra
 * rompe la suite del lado no tocado.
 */
interface CasoDelFixture {
  descripcion: string
  precio_proveedor: number
  descuento: number
  utilidad_porcentaje: number
  costo_goma: number
  objeto_imp: string
  costo_con_descuento: number
  costo_total: number
  precio_venta_crudo_sin_iva: number
  precio_unitario_sin_iva: number
  precio_unitario_con_iva: number
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
      caso.objeto_imp,
    )

    expect(cadena.costo_con_descuento).toBe(caso.costo_con_descuento)
    expect(cadena.costo_total).toBe(caso.costo_total)
    // El crudo se verifica aparte del final para que un fallo distinga si se rompió el markup o el
    // redondeo (ver 024).
    expect(cadena.precio_venta_crudo_sin_iva).toBe(caso.precio_venta_crudo_sin_iva)
    expect(cadena.precio_unitario_sin_iva).toBe(caso.precio_unitario_sin_iva)
    expect(precioConIva(cadena.precio_unitario_sin_iva, factorIva(caso.objeto_imp))).toBe(
      caso.precio_unitario_con_iva,
    )
    expect(cadena.utilidad).toBe(caso.utilidad)
  })

  it('sin goma el costo total es el costo del aparato', () => {
    // Con costo_goma en 0 la fórmula de 014 y la de 011 son la misma operación. Omitir el argumento
    // debe dar exactamente lo mismo que pasar 0 (ver specs/014-costo-elaboracion-goma.md).
    for (const caso of casos.filter((c) => c.costo_goma === 0 && c.objeto_imp === '02')) {
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

describe('factorIva', () => {
  it('solo el objeto de impuesto 02 lleva el 16% encima', () => {
    expect(factorIva('02')).toBe(1.16)
    expect(factorIva('01')).toBe(1)
    expect(factorIva('03')).toBe(1)
    expect(factorIva('04')).toBe(1)
    expect(factorIva(null)).toBe(1)
    expect(factorIva(undefined)).toBe(1)
  })
})

describe('redondearAPesoEntero', () => {
  it('deja el precio con IVA en un peso entero sin bajar el markup', () => {
    // Barrido de 024: es la prueba que detecta los enteros inalcanzables. Una batería de casos
    // escogidos a mano no los habría encontrado, y de hecho fue así como aparecieron.
    //
    // Las fallas se acumulan y se afirman una sola vez al final: 400,000 llamadas a `expect` dentro
    // del ciclo tardan más que el timeout de Vitest, y el reporte de la primera desviación es más
    // útil que el de un `expect` suelto.
    const fallas: string[] = []

    for (const factor of [1.16, 1]) {
      for (let crudoCentavos = 1; crudoCentavos <= 200_000; crudoCentavos++) {
        const crudo = crudoCentavos / 100
        const final = redondearAPesoEntero(crudo, factor)
        const conIva = redondeo2(final * factor)
        const ajuste = conIva - redondeo2(crudo * factor)

        // 1. El precio que ve el cliente es un peso entero exacto.
        // 2. El redondeo nunca baja el precio, así que nunca erosiona el markup.
        // 3. El ajuste se mantiene por debajo de dos pesos: nunca hacen falta dos incrementos de
        //    objetivo, porque no hay inalcanzables consecutivos.
        if (conIva !== Math.floor(conIva) || final < crudo || ajuste >= 2) {
          fallas.push(`crudo ${crudo} con factor ${factor} -> ${final} (con IVA ${conIva})`)
        }
      }
    }

    expect(fallas.slice(0, 5)).toEqual([])
  })

  it('con factor 1 es un techo al peso', () => {
    expect(redondearAPesoEntero(201.28, 1)).toBe(202)
    expect(redondearAPesoEntero(150, 1)).toBe(150)
    expect(redondearAPesoEntero(0.01, 1)).toBe(1)
  })

  it('sube al siguiente peso cuando el objetivo es inalcanzable', () => {
    // Ningún precio sin IVA de dos decimales produce $7.00 exactos.
    expect(redondearAPesoEntero(5.2, 1.16)).toBe(6.9)
    expect(redondeo2(6.9 * 1.16)).toBe(8)
  })

  it('no mueve un precio cuyo producto con IVA ya es entero', () => {
    expect(redondearAPesoEntero(200, 1.16)).toBe(200)
    expect(redondeo2(200 * 1.16)).toBe(232)
  })

  it('deja el cero en cero sin forzar un minimo', () => {
    expect(redondearAPesoEntero(0, 1.16)).toBe(0)
    expect(redondearAPesoEntero(0, 1)).toBe(0)
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
