<?php

namespace App\Enums;

/**
 * Tipo de descuento (por línea o global) de una factura: porcentaje o monto fijo, a elección
 * del usuario (ver 007-facturacion.md, supuesto #4).
 */
enum TipoDescuento: string
{
    case Porcentaje = 'porcentaje';
    case Monto = 'monto';
}
