import type { TipoDescuento } from '../stores/facturas'

/**
 * El descuento permanente del cliente, aplicado a las líneas del mostrador (ver
 * 015-descuento-permanente-cliente.md y 029-pwa-mostrador.md).
 *
 * Vive aquí y no dentro de una pantalla porque lo usan dos momentos distintos: al agregar un
 * artículo y al cambiar de cliente con artículos ya capturados. Escrito dos veces, un día uno de
 * los dos se quedaría atrás y la cotización del celular dejaría de cuadrar con la de la
 * computadora.
 */

export interface LineaConDescuento {
  descuento_tipo: TipoDescuento | null
  descuento_valor: number | null
}

export function descuentoDeCliente(porcentaje: number): LineaConDescuento {
  return porcentaje > 0
    ? { descuento_tipo: 'porcentaje', descuento_valor: porcentaje }
    : { descuento_tipo: null, descuento_valor: null }
}

/**
 * Cambiar de cliente reemplaza el descuento de todo lo ya capturado, misma regla que el formulario
 * de escritorio: lo capturado antes se pensó para otro cliente (ver 015, supuesto 12).
 */
export function reaplicarDescuento<T extends LineaConDescuento>(
  lineas: T[],
  porcentaje: number,
): T[] {
  return lineas.map((linea) => ({ ...linea, ...descuentoDeCliente(porcentaje) }))
}
