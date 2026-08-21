<?php

namespace App\Models;

use App\Services\PrecioArticuloCalculator;
use Database\Factories\CatalogoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'proveedor_id',
    'nombre',
    'descuento',
    'utilidad_porcentaje',
    'utilidad_distribuidor_porcentaje',
])]
class Catalogo extends Model
{
    /** @use HasFactory<CatalogoFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'descuento' => 0,
        'utilidad_porcentaje' => 0,
        'utilidad_distribuidor_porcentaje' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function articulos(): HasMany
    {
        return $this->hasMany(Articulo::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'descuento' => 'decimal:2',
            'utilidad_porcentaje' => 'decimal:2',
            'utilidad_distribuidor_porcentaje' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Recálculo (ver 011-precio-proveedor-utilidad.md y 033-precio-distribuidor.md): al editar
        // el descuento o cualquiera de los dos porcentajes de utilidad del catálogo, los artículos ya
        // existentes actualizan su cadena de precios. Se recorre cada artículo y se recalcula en PHP
        // con PrecioArticuloCalculator (la misma lógica que el resto de la app) en lugar de hacerlo en
        // SQL, porque el techo a 2 decimales (CEIL) no es portable entre MySQL y SQLite.
        static::updated(function (self $catalogo): void {
            $descuento = (float) $catalogo->descuento;
            $utilidad = (float) $catalogo->utilidad_porcentaje;
            $utilidadDistribuidor = (float) $catalogo->utilidad_distribuidor_porcentaje;

            // Un cambio de descuento mueve AMBOS precios de TODOS los artículos del catálogo,
            // incluidos los que tienen utilidad propia (de cualquiera de los dos tipos), porque
            // cambia el costo del que parten los dos. El costo de goma no lo toca el descuento (ver
            // 014-costo-elaboracion-goma.md), pero sí entra en el costo total del que se calcula el
            // precio directo (el distribuidor nunca la lleva, ver 033).
            if ($catalogo->wasChanged('descuento')) {
                foreach ($catalogo->articulos()->get() as $articulo) {
                    $utilidadEfectiva = (float) ($articulo->utilidad_porcentaje ?? $utilidad);
                    $utilidadDistribuidorEfectiva = (float) ($articulo->utilidad_distribuidor_porcentaje ?? $utilidadDistribuidor);
                    $cadena = PrecioArticuloCalculator::calcularCadena(
                        (float) $articulo->precio_proveedor,
                        $descuento,
                        $utilidadEfectiva,
                        (float) $articulo->costo_goma,
                        $articulo->objeto_imp,
                        $utilidadDistribuidorEfectiva,
                    );

                    $articulo->update([
                        'costo_con_descuento' => $cadena['costo_con_descuento'],
                        'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
                        'precio_distribuidor_sin_iva' => $cadena['precio_distribuidor_sin_iva'],
                    ]);
                }
            }

            // Un cambio de utilidad_porcentaje mueve el precio directo SOLO de los artículos que
            // heredan ese porcentaje (los que tienen utilidad_porcentaje en NULL); los que tienen
            // porcentaje propio conservan su precio directo.
            if ($catalogo->wasChanged('utilidad_porcentaje')) {
                foreach ($catalogo->articulos()->whereNull('utilidad_porcentaje')->get() as $articulo) {
                    // El costo no se mueve, pero el precio sí pasa por el redondeo al peso entero
                    // (ver 024-precios-sin-centavos.md): calcular solo el markup dejaría precios con
                    // centavos por esta única vía.
                    $precioCrudo = PrecioArticuloCalculator::precioVentaSinIva(
                        $articulo->costo_total,
                        $utilidad,
                    );

                    $articulo->update([
                        'precio_unitario_sin_iva' => PrecioArticuloCalculator::redondearAPesoEntero(
                            $precioCrudo,
                            PrecioArticuloCalculator::factorIva($articulo->objeto_imp),
                        ),
                    ]);
                }
            }

            // Espejo del bloque anterior para el distribuidor (ver 033-precio-distribuidor.md): mueve
            // solo a los artículos que heredan utilidad_distribuidor_porcentaje del catálogo, y parte
            // de costo_con_descuento, nunca de costo_total (sin goma).
            if ($catalogo->wasChanged('utilidad_distribuidor_porcentaje')) {
                foreach ($catalogo->articulos()->whereNull('utilidad_distribuidor_porcentaje')->get() as $articulo) {
                    $precioCrudo = PrecioArticuloCalculator::precioVentaSinIva(
                        (float) $articulo->costo_con_descuento,
                        $utilidadDistribuidor,
                    );

                    $articulo->update([
                        'precio_distribuidor_sin_iva' => PrecioArticuloCalculator::redondearAPesoEntero(
                            $precioCrudo,
                            PrecioArticuloCalculator::factorIva($articulo->objeto_imp),
                        ),
                    ]);
                }
            }
        });
    }
}
