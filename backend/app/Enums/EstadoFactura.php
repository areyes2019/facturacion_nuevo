<?php

namespace App\Enums;

/**
 * Máquina de estados de una Factura (ver 007-facturacion.md, adición técnica A).
 */
enum EstadoFactura: string
{
    case Borrador = 'borrador';
    case Pendiente = 'pendiente';
    case Timbrada = 'timbrada';
    case Cancelada = 'cancelada';

    /**
     * Una factura solo es editable/eliminable mientras no se ha timbrado con éxito.
     */
    public function esEditable(): bool
    {
        return in_array($this, [self::Borrador, self::Pendiente], true);
    }
}
