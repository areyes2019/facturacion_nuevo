<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste global del usuario, guardado como clave→valor (ver 014-costo-elaboracion-goma.md).
 *
 * Sin soft deletes: un ajuste se edita, no se elimina. Una clave que nunca se ha guardado
 * simplemente no tiene fila, y su valor de fábrica lo aporta ClaveConfiguracion.
 */
#[Fillable([
    'clave',
    'valor',
])]
class Configuracion extends Model
{
    protected $table = 'configuraciones';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
