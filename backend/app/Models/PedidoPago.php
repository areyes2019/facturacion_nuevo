<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Pago de un pedido de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * Sin `tipo`, a diferencia de `CotizacionPago`: el estado del pedido se deriva de la suma de sus
 * pagos, así que etiquetar cada uno como "anticipo" o "saldo" no decide nada y solo abriría la
 * puerta a que la etiqueta y el importe se contradigan. Se conserva `cuenta_id` por la misma razón
 * que 008: un pedido no es un CFDI y la forma de pago fiscal no se timbra desde aquí; lo que
 * importa es a qué cuenta entra el dinero.
 *
 * `registrado_al_entregar` distingue el pago que cerró la venta del que se capturó en el mostrador.
 * Todos son iguales en mecánica —monto capturado, cuenta elegida por el usuario, movimiento de
 * Tesorería— y la bandera no cambia nada de eso; sirve para dos cosas: nombrar el movimiento en
 * Tesorería y para que "Deshacer" sepa que esa entrega ya pasó por una confirmación y no debe
 * revertirse a ciegas.
 */
#[Fillable([
    'pedido_id',
    'fecha_pago',
    'monto',
    'cuenta_id',
    'registrado_al_entregar',
])]
class PedidoPago extends Model
{
    protected $table = 'pedido_pagos';

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function movimiento(): MorphOne
    {
        return $this->morphOne(Movimiento::class, 'documentable');
    }

    /**
     * Concepto del movimiento automático de Tesorería, generado por el sistema y no editable por el
     * usuario (RN-009 de 010-tesoreria.md).
     */
    public function conceptoMovimiento(): string
    {
        $origen = $this->registrado_al_entregar ? 'Saldo al entregar' : 'Pago';

        return $origen.' de Pedido '.$this->pedido->folioFormateado();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_pago' => 'date',
            'monto' => 'decimal:2',
            'registrado_al_entregar' => 'boolean',
        ];
    }
}
