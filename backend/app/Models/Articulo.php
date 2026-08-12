<?php

namespace App\Models;

use App\Enums\ObjetoImpuesto;
use App\Enums\TamanoGoma;
use App\Services\PrecioArticuloCalculator;
use Database\Factories\ArticuloFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'catalogo_id',
    'nombre',
    'modelo',
    'clave_prod_serv',
    'clave_unidad',
    'objeto_imp',
    'precio_proveedor',
    'utilidad_porcentaje',
    'tamano_goma',
    'costo_goma',
    'costo_con_descuento',
    'precio_unitario_sin_iva',
])]
class Articulo extends Model
{
    /** @use HasFactory<ArticuloFactory> */
    use HasFactory, SoftDeletes;

    /** Tasa general de IVA en México; no se contempla tasa 0%, exento ni IVA fronterizo (ver 006-gestion-articulos.md). */
    private const TASA_IVA_GENERAL = 1.16;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(Catalogo::class);
    }

    /**
     * Historial de inventario del artículo (ver 017-inventario.md). Las columnas `existencia`,
     * `faltante_pendiente`, `minimo` y `maximo` viven en esta misma tabla, pero deliberadamente
     * **fuera** de `#[Fillable]`: solo `InventarioService` las escribe, y siempre junto con su
     * movimiento en la misma transacción.
     */
    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    /**
     * Dinero invertido en las piezas que hay hoy, sin IVA. Se valúa al costo **actual** del
     * artículo, no al costo al que entró cada pieza (ver 017, "Valuación al costo de hoy").
     */
    protected function dineroInvertido(): Attribute
    {
        return Attribute::get(fn (): float => round((int) $this->existencia * $this->costo_total, 2));
    }

    /**
     * Lo que se ganaría vendiendo hoy todas las piezas a precio de lista, sin IVA y sin descuentos
     * de cliente (015).
     */
    protected function beneficioPotencial(): Attribute
    {
        return Attribute::get(fn (): float => round((int) $this->existencia * $this->utilidad, 2));
    }

    /**
     * Un mínimo en 0 significa "no me avises de este artículo"; un faltante pendiente, en cambio,
     * siempre pide reposición, porque es mercancía que ya se vendió sin respaldo en existencia.
     */
    protected function porPedir(): Attribute
    {
        return Attribute::get(fn (): bool => ((int) $this->minimo > 0 && (int) $this->existencia <= (int) $this->minimo)
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
     * Precio con IVA a la tasa general (16%), calculado solo para mostrarse; no se persiste.
     */
    protected function precioUnitarioConIva(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(((float) $this->precio_unitario_sin_iva) * self::TASA_IVA_GENERAL, 2),
        );
    }

    /**
     * Costo total por unidad (sin IVA): costo del aparato ya con descuento + costo de la goma.
     *
     * No se persiste: es la suma de dos columnas, mismo criterio por el que tampoco se persiste la
     * utilidad (ver 014-costo-elaboracion-goma.md). Ordenar por él se traduce a un ORDER BY sobre
     * la expresión.
     */
    protected function costoTotal(): Attribute
    {
        return Attribute::make(
            get: fn (): float => PrecioArticuloCalculator::costoTotal(
                (float) $this->costo_con_descuento,
                (float) $this->costo_goma,
            ),
        );
    }

    /**
     * Utilidad en pesos por unidad (sin IVA): precio de venta − costo total. No se persiste; es una
     * resta de dos valores derivados (ver 011-precio-proveedor-utilidad.md).
     */
    protected function utilidad(): Attribute
    {
        return Attribute::make(
            get: fn (): float => PrecioArticuloCalculator::utilidad(
                (float) $this->precio_unitario_sin_iva,
                $this->costo_total,
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'objeto_imp' => ObjetoImpuesto::class,
            'tamano_goma' => TamanoGoma::class,
            'precio_proveedor' => 'decimal:2',
            'utilidad_porcentaje' => 'decimal:2',
            'costo_goma' => 'decimal:2',
            'costo_con_descuento' => 'decimal:2',
            'precio_unitario_sin_iva' => 'decimal:2',
            'existencia' => 'integer',
            'faltante_pendiente' => 'integer',
            'minimo' => 'integer',
            'maximo' => 'integer',
        ];
    }
}
