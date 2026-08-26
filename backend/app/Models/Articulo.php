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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `imagen_ruta` vive en esta tabla pero deliberadamente **fuera** de `#[Fillable]`: no es un dato
 * que el cliente envíe en el alta o la edición del artículo, sino el resultado de haber guardado un
 * archivo. Solo `ImagenArticuloService` la escribe (ver 020-imagenes-articulos.md), mismo criterio
 * que `precio_con_descuento` en 009 y que las columnas de inventario en 017.
 */
#[Fillable([
    'catalogo_id',
    'nombre',
    'modelo',
    'clave_prod_serv',
    'clave_unidad',
    'objeto_imp',
    'precio_proveedor',
    'utilidad_porcentaje',
    'utilidad_distribuidor_porcentaje',
    'tamano_goma',
    'costo_goma',
    'costo_con_descuento',
    'precio_unitario_sin_iva',
    'precio_distribuidor_sin_iva',
])]
class Articulo extends Model
{
    /** @use HasFactory<ArticuloFactory> */
    use HasFactory, SoftDeletes;

    /** Directorio de las imágenes dentro del disco privado (ver 020-imagenes-articulos.md). */
    public const DIRECTORIO_IMAGENES = 'articulos';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(Catalogo::class);
    }

    /**
     * Historial de inventario del artículo (ver 017-inventario.md). Se relaciona con `articulo_id`
     * y no con `Existencia` a propósito: si el usuario quita y vuelve a marcar el artículo en
     * existencias, el historial completo se sigue viendo igual.
     */
    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
    }

    /**
     * La fila de la bodega curada (ver 017-inventario.md, revisión del 2026-08-26). `null` cuando
     * el artículo nunca se marcó "en existencias" — esa ausencia **es** la marca, no una fila en
     * ceros. Solo `InventarioService` escribe sus columnas.
     */
    public function existencia(): HasOne
    {
        return $this->hasOne(Existencia::class);
    }

    /**
     * Identificador de la versión actual de la imagen, o `null` si el artículo no tiene.
     *
     * Es el nombre del archivo sin extensión (`{id}-{8 al azar}`), y el frontend lo agrega como
     * parámetro a la URL de la imagen. Como cada subida genera un nombre nuevo, la URL cambia con
     * ella y el navegador va por la foto nueva en vez de servir la anterior desde su caché — que es
     * justo el problema que hacía irreproducible reemplazar una imagen (ver 020-imagenes-articulos.md).
     *
     * Se usa el nombre completo y no solo la parte aleatoria: cualquier trozo que cambie en cada
     * subida sirve, y parsear menos es una cosa menos que se puede romper.
     */
    protected function imagenVersion(): Attribute
    {
        return Attribute::get(fn (): ?string => filled($this->imagen_ruta)
            ? pathinfo((string) $this->imagen_ruta, PATHINFO_FILENAME)
            : null);
    }

    /**
     * Precio que ve el cliente, calculado solo para mostrarse; no se persiste.
     *
     * El factor sale del `objeto_imp` del artículo (ver 024-precios-sin-centavos.md): solo los que
     * sí causan impuesto llevan el 16% encima. Para cualquier artículo guardado por la cadena, este
     * valor es un peso entero exacto.
     */
    protected function precioUnitarioConIva(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(
                ((float) $this->precio_unitario_sin_iva) * PrecioArticuloCalculator::factorIva($this->objeto_imp),
                2,
            ),
        );
    }

    /**
     * Precio distribuidor que ve el cliente, calculado solo para mostrarse; no se persiste (ver
     * 033-precio-distribuidor.md). Espejo exacto de `precioUnitarioConIva`, mismo factor de IVA.
     */
    protected function precioDistribuidorConIva(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(
                ((float) $this->precio_distribuidor_sin_iva) * PrecioArticuloCalculator::factorIva($this->objeto_imp),
                2,
            ),
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
     * Utilidad en pesos por unidad del precio distribuidor (sin IVA): precio distribuidor − costo
     * con descuento, sin la goma, porque el distribuidor tampoco la paga (ver
     * 033-precio-distribuidor.md, 035-ajustes-tabla-articulos.md). Espejo exacto de `utilidad`, solo
     * cambia qué precio y qué costo se le pasan a la misma resta.
     */
    protected function utilidadDistribuidor(): Attribute
    {
        return Attribute::make(
            get: fn (): float => PrecioArticuloCalculator::utilidad(
                (float) $this->precio_distribuidor_sin_iva,
                (float) $this->costo_con_descuento,
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
            'utilidad_distribuidor_porcentaje' => 'decimal:2',
            'costo_goma' => 'decimal:2',
            'costo_con_descuento' => 'decimal:2',
            'precio_unitario_sin_iva' => 'decimal:2',
            'precio_distribuidor_sin_iva' => 'decimal:2',
        ];
    }
}
