<?php

namespace App\Enums;

/**
 * Forma de pago del envío, no del producto (ver 038-produccion-ordenes-trabajo.md).
 *
 * Solo dos casos: `Prepagado` es dinero real que ya entró a caja y genera un movimiento de
 * Tesorería al capturar el formulario de envío; `PorCobrar` lo cobra el repartidor directo al
 * cliente final y nunca toca la caja ni Tesorería del negocio.
 */
enum FormaPagoEnvio: string
{
    case Prepagado = 'prepagado';
    case PorCobrar = 'por_cobrar';

    public function texto(): string
    {
        return match ($this) {
            self::Prepagado => 'Prepagado',
            self::PorCobrar => 'Por cobrar',
        };
    }
}
