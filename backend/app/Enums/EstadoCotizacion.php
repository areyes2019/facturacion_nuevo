<?php

namespace App\Enums;

/**
 * Máquina de estados de una Cotización (ver 008-cotizaciones.md, supuesto #5).
 */
enum EstadoCotizacion: string
{
    case Borrador = 'borrador';
    case Enviada = 'enviada';
    case Pagada = 'pagada';
    case ProductoEntregado = 'producto_entregado';

    /**
     * Editable en borrador y enviada (incluso con anticipo ya pagado); bloqueada al llegar a
     * pagada/producto_entregado (ver 008-cotizaciones.md, supuesto #18).
     */
    public function esEditable(): bool
    {
        return in_array($this, [self::Borrador, self::Enviada], true);
    }
}
