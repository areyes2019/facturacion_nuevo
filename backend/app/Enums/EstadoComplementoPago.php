<?php

namespace App\Enums;

/**
 * Estado de timbrado de un ComplementoPago (mismo patrón que el timbrado de Factura, ver
 * 007-facturacion.md).
 */
enum EstadoComplementoPago: string
{
    case Pendiente = 'pendiente';
    case Timbrado = 'timbrado';
    case Error = 'error';
}
