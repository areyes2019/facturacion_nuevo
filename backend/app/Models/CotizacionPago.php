<?php

namespace App\Models;

use App\Enums\TipoPagoCotizacion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * `cuenta_id` reemplazó a `forma_pago` (catálogo SAT c_FormaPago): una cotización no es un CFDI, así
 * que la forma de pago nunca se timbraba y era meramente informativa; la cuenta en cambio determina
 * a dónde entra el dinero (ver 010-tesoreria.md). Esto no afecta el `forma_pago` de
 * ComplementoPago (007), que sí es un dato fiscal del CFDI.
 */
#[Fillable([
    'cotizacion_id',
    'tipo',
    'fecha_pago',
    'monto',
    'cuenta_id',
    'registrado_al_entregar',
])]
class CotizacionPago extends Model
{
    protected $table = 'cotizacion_pagos';

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * Movimiento de ingreso que este pago generó en Tesorería. Es la única fuente de movimientos
     * automáticos del sistema hoy (ver 010-tesoreria.md).
     */
    public function movimiento(): MorphOne
    {
        return $this->morphOne(Movimiento::class, 'documentable');
    }

    /**
     * Concepto que lleva el movimiento automático, generado por el sistema a partir del documento
     * origen y no editable por el usuario (RN-009).
     */
    public function conceptoMovimiento(): string
    {
        $tipo = match ($this->tipo) {
            TipoPagoCotizacion::Anticipo => 'Anticipo',
            TipoPagoCotizacion::Saldo => 'Saldo',
            TipoPagoCotizacion::PagoTotal => 'Pago total',
        };

        return $tipo.' de Cotización COT-'.str_pad((string) $this->cotizacion->folio, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Saldo que quedaba pendiente justo después de este pago, no el saldo actual de la cotización
     * (ver 040-recibo-anticipo-cotizacion.md): suma solo los pagos creados hasta este (inclusive,
     * por `id`, que respeta el orden de creación), así que un recibo generado después de un pago
     * posterior no cambia.
     */
    public function saldoPendienteTrasEste(): float
    {
        $acumuladoHastaEste = $this->cotizacion->pagos()
            ->where('id', '<=', $this->id)
            ->sum('monto');

        return max(0, (float) $this->cotizacion->total - (float) $acumuladoHastaEste);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoPagoCotizacion::class,
            'fecha_pago' => 'date',
            'monto' => 'decimal:2',
            'registrado_al_entregar' => 'boolean',
        ];
    }
}
