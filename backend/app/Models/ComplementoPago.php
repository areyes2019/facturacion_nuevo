<?php

namespace App\Models;

use App\Enums\EstadoComplementoPago;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'factura_id',
    'fecha_pago',
    'monto',
    'forma_pago',
    'estado',
    'facturapi_invoice_id',
    'uuid_fiscal',
    'sello_cfdi',
    'cadena_original_sat',
    'error_timbrado',
])]
class ComplementoPago extends Model
{
    protected $table = 'complementos_pago';

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_pago' => 'date',
            'monto' => 'decimal:2',
            'estado' => EstadoComplementoPago::class,
        ];
    }
}
