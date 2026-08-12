<?php

namespace App\Enums;

/**
 * Refleja el `cancellation_status` que devuelve facturapi.io al cancelar un CFDI: la
 * cancelación puede requerir aceptación del receptor o validación del SAT, no siempre es
 * inmediata (ver 007-facturacion.md, adición técnica 38).
 */
enum EstadoCancelacion: string
{
    case None = 'none';
    case Pending = 'pending';
    case Verifying = 'verifying';
    case Accepted = 'accepted';
}
