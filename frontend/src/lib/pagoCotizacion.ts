import type { TipoPagoCotizacion } from '../stores/cotizaciones'

/**
 * De qué tipo es el pago que se está registrando desde el mostrador (ver 031-mostrador-consulta.md).
 *
 * El backend exige `anticipo`, `saldo` o `pago_total` (ver 008-cotizaciones.md), y esa es una
 * pregunta que el usuario no sabe responder con el cliente enfrente. El monto sí lo sabe, y del
 * monto se deduce el tipo:
 *
 * - Igual al saldo pendiente: `pago_total` si la cotización no tenía ningún pago, `saldo` si ya
 *   tenía alguno.
 * - Menor: `anticipo`. Como una cotización admite **un solo** anticipo, la pantalla deja el monto
 *   fijo en el saldo cuando ya hay uno registrado, en vez de dejar capturar algo que el servidor va
 *   a rechazar.
 *
 * Se compara en centavos y no en pesos: dos flotantes que valen lo mismo pueden diferir en la
 * decimoquinta cifra, y ahí un pago completo se mandaría como anticipo.
 */
export function tipoDePago(
  saldoPendiente: number,
  montoCapturado: number,
  pagosPrevios: number,
): TipoPagoCotizacion {
  if (Math.round(montoCapturado * 100) < Math.round(saldoPendiente * 100)) return 'anticipo'

  return pagosPrevios === 0 ? 'pago_total' : 'saldo'
}
