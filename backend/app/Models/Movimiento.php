<?php

namespace App\Models;

use App\Enums\TipoMovimiento;
use Database\Factories\MovimientoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Cualquier operación que modifica el saldo de una cuenta (ver 010-tesoreria.md).
 */
#[Fillable([
    'cuenta_id',
    'tipo',
    'monto',
    'fecha',
    'concepto',
    'transferencia_id',
])]
class Movimiento extends Model
{
    /** @use HasFactory<MovimientoFactory> */
    use HasFactory;

    protected $table = 'movimientos';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * Registro que originó el movimiento; `null` para los movimientos manuales.
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Un movimiento es automático si lo generó un documento de otro módulo. Los automáticos son de
     * solo lectura desde Tesorería: su corrección se hace sobre el documento origen.
     */
    protected function esAutomatico(): Attribute
    {
        return Attribute::get(fn (): bool => $this->documentable_type !== null);
    }

    /**
     * Cuánto suma (o resta) este movimiento al saldo de su cuenta.
     */
    public function efectoEnSaldo(): float
    {
        return $this->tipo->efectoEnSaldo((float) $this->monto);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimiento::class,
            'monto' => 'decimal:2',
            'fecha' => 'date',
        ];
    }
}
