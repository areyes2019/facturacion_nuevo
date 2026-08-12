<?php

namespace App\Models;

use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimientoInventario;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un renglón del historial de inventario (ver 017-inventario.md).
 *
 * Solo de consulta: no hay endpoints que lo editen ni lo borren, porque el historial es la única
 * evidencia de por qué un artículo tiene la existencia que tiene. Un error se corrige con un
 * movimiento nuevo, no borrando el viejo.
 */
#[Fillable([
    'articulo_id',
    'tipo',
    'motivo',
    'cantidad',
    'existencia_resultante',
    'faltante_resultante',
    'nota',
])]
class MovimientoInventario extends Model
{
    /**
     * Eloquent pluraliza en inglés y de "MovimientoInventario" inferiría `movimiento_inventarios`;
     * se declara explícito desde el primer commit, misma lección ya pagada en 005, 008 y 012.
     */
    protected $table = 'movimientos_inventario';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `withTrashed()` porque el artículo usa soft delete y su historial debe seguir siendo legible
     * después de borrarlo (ver 017, supuesto 29).
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class)->withTrashed();
    }

    /**
     * Documento que originó el movimiento (OrdenCompra, Factura o Cotizacion); `null` en los
     * ajustes manuales. Mismo patrón que el `Movimiento` de Tesorería (010).
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function esAutomatico(): Attribute
    {
        return Attribute::get(fn (): bool => $this->documentable_type !== null);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoInventario::class,
            'motivo' => MotivoMovimientoInventario::class,
            'cantidad' => 'integer',
            'existencia_resultante' => 'integer',
            'faltante_resultante' => 'integer',
        ];
    }
}
