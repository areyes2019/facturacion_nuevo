<?php

namespace App\Models;

use App\Enums\TipoCuenta;
use Database\Factories\CuentaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lugar donde se almacena el dinero del negocio (Caja General, BBVA, Mercado Pago…).
 *
 * `saldo_inicial` no aparece como fillable de edición por convención sino porque es inmutable tras
 * la creación (ver UpdateCuentaRequest); para corregirlo se registra un Ajuste, que deja rastro en
 * el historial de movimientos.
 */
#[Fillable([
    'nombre',
    'tipo',
    'saldo_inicial',
    'saldo_actual',
    'activa',
])]
class Cuenta extends Model
{
    /** @use HasFactory<CuentaFactory> */
    use HasFactory;

    protected $table = 'cuentas';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'saldo_inicial' => 0,
        'saldo_actual' => 0,
        'activa' => true,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoCuenta::class,
            'saldo_inicial' => 'decimal:2',
            'saldo_actual' => 'decimal:2',
            'activa' => 'boolean',
        ];
    }
}
