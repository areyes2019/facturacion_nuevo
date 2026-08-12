/**
 * Cadena de cálculo de precios de un artículo (ver specs/011-precio-proveedor-utilidad.md y
 * specs/014-costo-elaboracion-goma.md).
 *
 *   precio_proveedor          (capturado)              $200.00
 *     ↓ × (1 − descuento / 100)                        descuento del catálogo
 *   costo_con_descuento       (calculado, persistido)  $200.00   ← costo del aparato
 *     ↓ + costo_goma                                   goma mediana
 *   costo_total               (calculado al leer)      $210.00
 *     ↓ × (1 + utilidad_efectiva / 100)                markup sobre el costo
 *   precio_unitario_sin_iva   (calculado, persistido)  $262.50
 *
 * El costo de goma entra DESPUÉS del descuento (que solo aplica a lo que le pagas al proveedor) y
 * ANTES del markup. Un artículo sin goma tiene costo_goma = 0 y la cadena queda idéntica a la de 011.
 *
 * Espejo exacto de backend/app/Services/PrecioArticuloCalculator.php. Ambas implementaciones se
 * verifican contra el mismo archivo de casos, shared/fixtures/precios-articulos.json: cambiar una
 * sin la otra rompe la suite del lado no tocado.
 */

/** Tasa general de IVA (ver 006-gestion-articulos.md). */
export const TASA_IVA_GENERAL = 1.16

/**
 * Redondeo matemático estándar a 2 decimales.
 *
 * `Math.round(valor * 100) / 100` no equivale al `round($valor, 2)` de PHP: este último corrige el
 * error de representación antes de redondear, y sin esa corrección un valor como 1.005 (que en
 * binario es 100.49999999999999 al escalar) baja a 1.00 en lugar de subir a 1.01. Se absorbe el
 * error sobre el valor ya expresado en centavos, igual que en `techo2`.
 */
export function redondeo2(valor: number): number {
  const centavos = valor * 100

  return Math.round(Math.round(centavos * 1_000_000) / 1_000_000) / 100
}

/**
 * Techo a 2 decimales, redondeando DESPUÉS de escalar a centavos.
 *
 * El orden es la parte sustancial de la definición y no es intercambiable:
 *
 *   - `Math.ceil(valor * 100) / 100` falla porque el producto costo × (1 + % / 100) arrastra error
 *     de representación en punto flotante.
 *   - Absorber el error antes de escalar corrige el valor pero vuelve a introducir error en la
 *     multiplicación por 100 (`0.07 * 100 === 7.000000000000001`), y termina cobrando un centavo de
 *     más: con costo $15.40 y 5% el resultado exacto es $16.17 y esa variante devuelve $16.18.
 *   - Redondear a 6 decimales sobre el valor ya expresado en centavos elimina ambas fuentes de
 *     error.
 */
export function techo2(valor: number): number {
  const centavos = valor * 100

  return Math.ceil(Math.round(centavos * 1_000_000) / 1_000_000) / 100
}

/** Costo con descuento = redondeo2(precio_proveedor × (1 − descuento / 100)). */
export function costoConDescuento(precioProveedor: number, descuento: number): number {
  return redondeo2(precioProveedor * (1 - descuento / 100))
}

/**
 * Costo total = redondeo2(costo_con_descuento + costo_goma).
 *
 * Los dos sumandos ya vienen con 2 decimales, así que el redondeo no cambia ningún valor de
 * negocio; está para que la suma en punto flotante no arrastre una cola de error hacia el `techo2`
 * del markup, que es sensible al último centavo.
 */
export function costoTotal(costoConDescuento: number, costoGoma: number): number {
  return redondeo2(costoConDescuento + costoGoma)
}

/** Precio de venta sin IVA = techo2(costo_total × (1 + utilidad_efectiva / 100)). */
export function precioVentaSinIva(costo: number, utilidadPorcentaje: number): number {
  return techo2(costo * (1 + utilidadPorcentaje / 100))
}

/** Utilidad en pesos = precio_unitario_sin_iva − costo_total (siempre sin IVA). */
export function utilidad(precioVenta: number, costo: number): number {
  return redondeo2(precioVenta - costo)
}

/** Precio con IVA, solo de referencia en pantalla (la columna persistida es la de sin IVA). */
export function precioConIva(precioVentaSinIva: number): number {
  return redondeo2(precioVentaSinIva * TASA_IVA_GENERAL)
}

export interface CadenaDePrecios {
  costo_con_descuento: number
  costo_total: number
  precio_unitario_sin_iva: number
  utilidad: number
}

/** Calcula la cadena completa a partir de los valores de entrada. */
export function calcularCadena(
  precioProveedor: number,
  descuento: number,
  utilidadPorcentaje: number,
  costoGoma = 0,
): CadenaDePrecios {
  const costoAparato = costoConDescuento(precioProveedor, descuento)
  const costo = costoTotal(costoAparato, costoGoma)
  const precioVenta = precioVentaSinIva(costo, utilidadPorcentaje)

  return {
    costo_con_descuento: costoAparato,
    costo_total: costo,
    precio_unitario_sin_iva: precioVenta,
    utilidad: utilidad(precioVenta, costo),
  }
}
