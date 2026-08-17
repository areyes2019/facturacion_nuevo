import { describe, expect, it } from 'vitest'
import { tipoDePago } from './pagoCotizacion'

describe('tipoDePago', () => {
  it('cobra el saldo completo de una cotización sin pagos como pago_total', () => {
    expect(tipoDePago(1160, 1160, 0)).toBe('pago_total')
  })

  it('cobra el saldo restante de una cotización con anticipo como saldo', () => {
    expect(tipoDePago(660, 660, 1)).toBe('saldo')
  })

  it('cobra menos que el saldo como anticipo', () => {
    expect(tipoDePago(1160, 500, 0)).toBe('anticipo')
  })

  // Sin comparar en centavos, 0.1 + 0.2 contra 0.3 mandaría un pago completo como anticipo.
  it('trata como pago completo un monto que solo difiere por el redondeo del flotante', () => {
    expect(tipoDePago(0.1 + 0.2, 0.3, 0)).toBe('pago_total')
  })

  // El monto nunca puede superar el saldo —el input lo topa y el backend lo rechaza—, pero si
  // llegara igual por arriba sigue siendo un cobro del saldo, no un anticipo.
  it('no llama anticipo a un monto mayor que el saldo', () => {
    expect(tipoDePago(100, 120, 1)).toBe('saldo')
  })
})
