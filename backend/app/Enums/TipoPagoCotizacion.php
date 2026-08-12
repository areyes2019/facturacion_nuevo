<?php

namespace App\Enums;

/**
 * Clasifica un registro de CotizacionPago; no cambia la lógica de acumulación hacia el estado
 * `pagada`, solo etiqueta el registro en el historial (ver 008-cotizaciones.md).
 */
enum TipoPagoCotizacion: string
{
    case Anticipo = 'anticipo';
    case Saldo = 'saldo';
    case PagoTotal = 'pago_total';
}
