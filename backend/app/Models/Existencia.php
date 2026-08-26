<?php

namespace App\Models;

use Database\Factories\ExistenciaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La bodega curada por el usuario (ver 017-inventario.md, revisión del 2026-08-26): una fila por
 * artículo marcado "en existencias". Un artículo sin fila aquí simplemente no es inventario.
 *
 * `existencia`, `faltante_pendiente`, `minimo` y `maximo` quedan deliberadamente **fuera** de
 * `#[Fillable]`: solo `InventarioService` las escribe, siempre junto con su movimiento en la misma
 * transacción.
 *
 * `SoftDeletes`: "quitar de existencias" borra la fila lógicamente. Si el artículo se vuelve a
 * marcar después, se restaura la misma fila en vez de crear una nueva, y no se pierden sus números.
 */
#[Fillable(['articulo_id'])]
class Existencia extends Model
{
    /** @use HasFactory<ExistenciaFactory> */
    use HasFactory, SoftDeletes;

    /**
     * El historial de movimientos (`MovimientoInventario`) se relaciona con `articulo_id`, no con
     * esta fila: si el usuario quita y vuelve a marcar un artículo, el historial completo se sigue
     * viendo igual, en vez de empezar de cero cada vez que esta fila va y viene.
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class)->withTrashed();
    }

    /**
     * Dinero invertido en las piezas que hay hoy, sin IVA. Se valúa al costo **actual** del
     * artículo, no al costo al que entró cada pieza (ver 017, "Valuación al costo de hoy").
     */
    protected function dineroInvertido(): Attribute
    {
        return Attribute::get(fn (): float => round((int) $this->existencia * $this->articulo->costo_total, 2));
    }

    /**
     * Lo que se ganaría vendiendo hoy todas las piezas a precio de lista, sin IVA y sin descuentos
     * de cliente (015).
     */
    protected function beneficioPotencial(): Attribute
    {
        return Attribute::get(fn (): float => round((int) $this->existencia * $this->articulo->utilidad, 2));
    }

    /**
     * Un mínimo en 0 significa "no me avises de este artículo"; un faltante pendiente, en cambio,
     * siempre pide reposición, porque es mercancía que ya se vendió sin respaldo en existencia.
     *
     * La comparación es **estrictamente menor que** (no "menor o igual"): cuando no hay máximo
     * capturado, el techo de la sugerencia es el propio mínimo, así que en `existencia == mínimo`
     * la cantidad sugerida sería 0 — quedaría marcado "por pedir" sin nada que sugerir pedir. Con
     * `<`, "por pedir" y "cantidad sugerida > 0" son siempre la misma condición.
     */
    protected function porPedir(): Attribute
    {
        return Attribute::get(fn (): bool => ((int) $this->minimo > 0 && (int) $this->existencia < (int) $this->minimo)
            || (int) $this->faltante_pendiente > 0);
    }

    /**
     * Cuánto conviene pedir: lo que falta para llegar al techo, más lo que se debe. Si no se
     * capturó un máximo, el techo es el propio mínimo.
     */
    protected function cantidadSugerida(): Attribute
    {
        return Attribute::get(function (): int {
            $techo = $this->maximo !== null ? (int) $this->maximo : (int) $this->minimo;

            return max($techo - (int) $this->existencia, 0) + (int) $this->faltante_pendiente;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'existencia' => 'integer',
            'faltante_pendiente' => 'integer',
            'minimo' => 'integer',
            'maximo' => 'integer',
        ];
    }
}
