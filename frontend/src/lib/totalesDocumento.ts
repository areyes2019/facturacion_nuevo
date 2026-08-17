import type { TasaIva, TipoDescuento } from '../stores/facturas'

/**
 * Espejo exacto de `FacturaTotalesCalculator` (backend) para el desglose en vivo de los
 * formularios de factura, cotización y orden de compra.
 *
 * La duplicación existe por necesidad: PHP es quien persiste y quien manda, TypeScript alimenta el
 * resumen del formulario sin depender de la red. Ambas implementaciones están atadas por el
 * fixture compartido `shared/fixtures/totales-documento.json`, que recorren PHPUnit y Vitest: si se
 * toca una fórmula sin tocar la otra, la suite del lado no tocado falla (ver
 * specs/012-ordenes-compra.md, adición técnica 42).
 */

export interface LineaCalculable {
  cantidad: number
  precio_unitario: number
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
  tasa_iva: TasaIva
}

export interface LineaCalculada {
  importe: number
  iva_importe: number
}

export interface TotalesDocumento {
  lineas: LineaCalculada[]
  subtotal: number
  total_descuento: number
  total_iva_16: number
  total_iva_0: number
  total_exento: number
  ajuste_al_peso: number
  total: number
  /** Monto monetario del descuento global, para mostrarlo por separado en el resumen. */
  descuento_global: number
}

export function redondeo2(valor: number): number {
  return Math.round(valor * 100) / 100
}

/**
 * Franja en la que un total que ya quedó por encima de un peso cerrado se deja donde está en vez de
 * brincar al siguiente (ver specs/030-total-al-peso-cerrado.md, adición técnica 14).
 */
const TOLERANCIA_PESO_CERRADO = 0.05

/**
 * Centavos que le faltan al total para quedar en un peso cerrado. Nunca es negativo y nunca pasa de
 * $0.99; un documento en ceros se queda en $0.00.
 */
export function ajusteAlPeso(total: number): number {
  const objetivo = Math.ceil(redondeo2(total - TOLERANCIA_PESO_CERRADO))

  return Math.max(0, redondeo2(objetivo - total))
}

function tasa(tasaIva: TasaIva): number {
  return tasaIva === '16' ? 0.16 : 0
}

export function calcularDescuento(
  base: number,
  tipo: TipoDescuento | null,
  valor: number | null,
): number {
  if (tipo === null || valor === null) return 0

  return redondeo2(tipo === 'porcentaje' ? base * (valor / 100) : valor)
}

/**
 * Reparte el monto del descuento global entre las líneas, proporcional al importe de cada una,
 * con la última absorbiendo el residuo de redondeo para que la suma de las partes sea exactamente
 * igual al descuento (evita descuadres de centavos).
 */
export function prorratear(
  importesPorLinea: number[],
  subtotal: number,
  descuentoGlobal: number,
): number[] {
  const total = importesPorLinea.length

  if (descuentoGlobal <= 0 || subtotal <= 0 || total === 0) {
    return importesPorLinea.map(() => 0)
  }

  const partes: number[] = []
  let acumulado = 0
  const ultimo = total - 1

  importesPorLinea.forEach((importe, i) => {
    if (i === ultimo) {
      partes.push(redondeo2(descuentoGlobal - acumulado))
      return
    }

    const parte = redondeo2((descuentoGlobal * importe) / subtotal)
    partes.push(parte)
    acumulado += parte
  })

  return partes
}

export function calcularTotales(
  lineas: LineaCalculable[],
  descuentoGlobalTipo: TipoDescuento | null,
  descuentoGlobalValor: number | null,
  redondearAlPeso = false,
): TotalesDocumento {
  // Primera pasada: importe de cada línea neto de su propio descuento, antes del global.
  const importesPorLinea: number[] = []
  let subtotal = 0
  let totalDescuentoLineas = 0

  for (const linea of lineas) {
    const bruto = redondeo2(linea.cantidad * linea.precio_unitario)
    const descuentoLinea = calcularDescuento(bruto, linea.descuento_tipo, linea.descuento_valor)
    const importe = redondeo2(bruto - descuentoLinea)

    importesPorLinea.push(importe)
    subtotal += importe
    totalDescuentoLineas += descuentoLinea
  }

  subtotal = redondeo2(subtotal)

  const descuentoGlobal = calcularDescuento(subtotal, descuentoGlobalTipo, descuentoGlobalValor)
  const partes = prorratear(importesPorLinea, subtotal, descuentoGlobal)

  // Segunda pasada: IVA de cada línea sobre el importe ya neto de su parte del descuento global.
  let totalIva16 = 0
  let totalIva0 = 0
  let totalExento = 0

  const lineasCalculadas: LineaCalculada[] = lineas.map((linea, i) => {
    const importe = importesPorLinea[i]
    const importeNetoFinal = redondeo2(importe - partes[i])
    const ivaImporte = redondeo2(importeNetoFinal * tasa(linea.tasa_iva))

    if (linea.tasa_iva === '16') totalIva16 += ivaImporte
    else if (linea.tasa_iva === '0') totalIva0 += ivaImporte
    else totalExento += ivaImporte

    // El "importe" que se muestra como Total de la fila es el neto ANTES del descuento global;
    // solo el IVA refleja el descuento ya prorrateado (mismo criterio que el backend).
    return { importe, iva_importe: ivaImporte }
  })

  totalIva16 = redondeo2(totalIva16)
  totalIva0 = redondeo2(totalIva0)
  totalExento = redondeo2(totalExento)

  const total = redondeo2(subtotal - descuentoGlobal + totalIva16 + totalIva0)
  const ajuste = redondearAlPeso ? ajusteAlPeso(total) : 0

  return {
    lineas: lineasCalculadas,
    subtotal,
    total_descuento: redondeo2(totalDescuentoLineas + descuentoGlobal),
    total_iva_16: totalIva16,
    total_iva_0: totalIva0,
    total_exento: totalExento,
    ajuste_al_peso: ajuste,
    total: redondeo2(total + ajuste),
    descuento_global: descuentoGlobal,
  }
}

/**
 * Importe neto de una línea (cantidad × precio, menos su propio descuento), que es lo que se
 * muestra como "Total" de la fila en la tabla de captura.
 */
export function importeNetoLinea(linea: LineaCalculable): number {
  const bruto = redondeo2(linea.cantidad * linea.precio_unitario)

  return redondeo2(bruto - calcularDescuento(bruto, linea.descuento_tipo, linea.descuento_valor))
}
