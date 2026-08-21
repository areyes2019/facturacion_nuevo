<?php

use App\Enums\ObjetoImpuesto;
use App\Services\PrecioArticuloCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Precio distribuidor (ver 033-precio-distribuidor.md): un segundo precio de venta por artículo,
     * calculado sobre el costo con descuento SIN goma y con su propia utilidad configurable.
     *
     * Todos los catálogos existentes arrancan en 0% de utilidad distribuidor, mismo patrón que
     * `utilidad_porcentaje` en 011. Todos los artículos existentes quedan con
     * `utilidad_distribuidor_porcentaje` en NULL (heredan ese 0%), así que su
     * `precio_distribuidor_sin_iva` arranca igual a su costo con descuento (sin markup) hasta que se
     * capture la utilidad real por catálogo o por artículo.
     */
    public function up(): void
    {
        Schema::table('catalogos', function (Blueprint $table) {
            $table->decimal('utilidad_distribuidor_porcentaje', 5, 2)->default(0)->after('utilidad_porcentaje');
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->decimal('utilidad_distribuidor_porcentaje', 5, 2)->nullable()->after('utilidad_porcentaje');
            $table->decimal('precio_distribuidor_sin_iva', 10, 2)->default(0)->after('precio_unitario_sin_iva');
        });

        // Recálculo con el calculador de la aplicación, no con SQL: el techo a 2 decimales y la
        // búsqueda del centavo no son portables entre MySQL y SQLite (mismo criterio que 011/014/024).
        $articulos = DB::table('articulos')
            ->join('catalogos', 'catalogos.id', '=', 'articulos.catalogo_id')
            ->select(
                'articulos.id',
                'articulos.precio_proveedor',
                'articulos.utilidad_porcentaje',
                'articulos.costo_goma',
                'articulos.objeto_imp',
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
                ObjetoImpuesto::tryFrom((string) $articulo->objeto_imp),
                0.0,
            );

            DB::table('articulos')->where('id', $articulo->id)->update([
                'precio_distribuidor_sin_iva' => $cadena['precio_distribuidor_sin_iva'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('catalogos', function (Blueprint $table) {
            $table->dropColumn('utilidad_distribuidor_porcentaje');
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn(['utilidad_distribuidor_porcentaje', 'precio_distribuidor_sin_iva']);
        });
    }
};
