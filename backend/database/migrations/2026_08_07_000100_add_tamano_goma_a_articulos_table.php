<?php

use App\Services\PrecioArticuloCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Costo de elaboración de goma por tamaño (ver 014-costo-elaboracion-goma.md).
     *
     * Todos los artículos existentes quedan SIN goma y con costo_goma en 0.00. No se asigna ninguna
     * categoría por omisión: un artículo que no es un sello no debe cargar un costo inventado, y la
     * categoría se elige a ojo porque no hay medidas capturadas de las que deducirla.
     *
     * El recálculo posterior debe producir CERO cambios de precio, porque con costo_goma en 0 la
     * cadena nueva y la vieja son la misma operación. Esa invariante es la verificación de que el
     * eslabón nuevo es transparente cuando no se usa, y está cubierta por un test dedicado.
     */
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->string('tamano_goma', 10)->nullable()->after('utilidad_porcentaje');
            $table->decimal('costo_goma', 10, 2)->default(0)->after('tamano_goma');
        });

        // Recálculo completo con el calculador de la aplicación, no con SQL: el techo a 2 decimales
        // no es portable entre MySQL y SQLite, y una versión SQL de la fórmula sería otra copia de
        // la lógica de precios que mantener sincronizada (mismo criterio que en 011).
        $articulos = DB::table('articulos')
            ->join('catalogos', 'catalogos.id', '=', 'articulos.catalogo_id')
            ->select(
                'articulos.id',
                'articulos.precio_proveedor',
                'articulos.utilidad_porcentaje',
                'articulos.costo_goma',
                'catalogos.descuento',
                'catalogos.utilidad_porcentaje as utilidad_catalogo',
            )
            ->get();

        foreach ($articulos as $articulo) {
            $cadena = PrecioArticuloCalculator::calcularCadena(
                (float) $articulo->precio_proveedor,
                (float) $articulo->descuento,
                (float) ($articulo->utilidad_porcentaje ?? $articulo->utilidad_catalogo),
                (float) $articulo->costo_goma,
            );

            DB::table('articulos')->where('id', $articulo->id)->update([
                'costo_con_descuento' => $cadena['costo_con_descuento'],
                'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn(['tamano_goma', 'costo_goma']);
        });
    }
};
