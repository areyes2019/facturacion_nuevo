<?php

namespace App\Enums;

/**
 * Máquina de estados de una Orden de compra (ver 012-ordenes-compra.md, supuesto #12).
 *
 * Secuencial, con dos retrocesos explícitos: editar una `enviada` la regresa a `borrador`, y
 * cancelar el pago de una `pagada` la regresa a `enviada`.
 */
enum EstadoOrdenCompra: string
{
    case Borrador = 'borrador';
    case Enviada = 'enviada';
    case Pagada = 'pagada';
    case Recibida = 'recibida';

    /**
     * Libremente editable mientras la orden no esté pagada; la vía para corregir una orden ya
     * pagada es cancelar el pago, que la regresa a `enviada` (ver 012, supuesto #8).
     */
    public function esEditable(): bool
    {
        return in_array($this, [self::Borrador, self::Enviada], true);
    }

    /**
     * Una orden bloquea el borrado de su proveedor mientras no haya cerrado su ciclo. Incluye
     * `borrador`: si hay un borrador colgando, borrarlo es un clic, y definir "activa" con
     * excepciones produce una regla que nadie recuerda seis meses después (ver 012, supuesto #25).
     */
    public function esActiva(): bool
    {
        return $this !== self::Recibida;
    }
}
