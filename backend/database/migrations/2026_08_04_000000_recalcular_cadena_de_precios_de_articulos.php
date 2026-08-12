<?php

use App\Services\PrecioArticuloCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recalcula la cadena de precios de todos los artículos con
     * PrecioArticuloCalculator (ver 011-precio-proveedor-utilidad.md).
     *
     * El recálculo es determinista y sin pérdida: `precio_proveedor` y `utilidad_porcentaje` son
     * entradas capturadas que no se tocan, y `costo_con_descuento` y `precio_unitario_sin_iva` se
     * derivan de ellas junto con el descuento del catálogo. Por eso la migración es idempotente y
     * puede volver a ejecutarse tras cualquier ajuste de la fórmula o del redondeo.
     *
     * Se hace en PHP y no en SQL por la misma razón que el recálculo en bloque de Catalogo: el
     * techo a 2 decimales no es portable entre MySQL y SQLite, y una versión SQL de la fórmula
     * sería una tercera copia de la lógica de precios que mantener sincronizada.
     */
    public function up(): void
    {
        $articulos = DB::table('articulos')
            ->join('catalogos', 'catalogos.id', '=', 'articulos.catalogo_id')
            ->select(
                'articulos.id',
                'articulos.precio_proveedor',
                'articulos.utilidad_porcentaje',
                'catalogos.descuento',
                'catalogos.utilidad_porcentaje as utilidad_catalogo',
            )
            ->get();

        foreach ($articulos as $articulo) {
            $cadena = PrecioArticuloCalculator::calcularCadena(
                (float) $articulo->precio_proveedor,
                (float) $articulo->descuento,
                (float) ($articulo->utilidad_porcentaje ?? $articulo->utilidad_catalogo),
            );

            // Solo las dos columnas persistidas: `calcularCadena` devuelve además el costo total,
            // que es una suma de columnas y no tiene columna propia (ver 014-costo-elaboracion-goma.md).
            DB::table('articulos')->where('id', $articulo->id)->update([
                'costo_con_descuento' => $cadena['costo_con_descuento'],
                'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
            ]);
        }
    }

    /**
     * Sin reverso: los valores recalculados se derivan de las entradas capturadas, que esta
     * migración no modifica. Volver atrás no requiere restaurar nada.
     */
    public function down(): void
    {
        //
    }
};
