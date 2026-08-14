<?php

use App\Enums\ObjetoImpuesto;
use App\Services\PrecioArticuloCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Redondeo del precio con IVA al peso entero (ver 024-precios-sin-centavos.md).
     *
     * Migración de datos, sin cambios de estructura: `precio_unitario_sin_iva` conserva su columna
     * decimal(10,2), su nombre y su significado. Lo que cambia es el valor que se guarda ahí, que
     * ahora es el que produce un precio con IVA entero.
     *
     * Es determinista e idempotente: `precio_proveedor`, `utilidad_porcentaje`, `costo_goma` y
     * `objeto_imp` son entradas capturadas que no se tocan, y todo lo demás se deriva de ellas.
     * Volver a correrla no mueve ningún valor.
     *
     * Los precios de venta existentes suben entre $0.00 y $1.99 (ver la nota sobre los enteros
     * inalcanzables en `PrecioArticuloCalculator::redondearAPesoEntero`).
     */
    public function up(): void
    {
        // Recálculo con el calculador de la aplicación, no con SQL: ni el techo a 2 decimales ni la
        // búsqueda del centavo son portables entre MySQL y SQLite, y una versión SQL sería otra
        // copia de la lógica de precios que mantener sincronizada (mismo criterio que en 011/014).
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
            );

            DB::table('articulos')->where('id', $articulo->id)->update([
                'costo_con_descuento' => $cadena['costo_con_descuento'],
                'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
            ]);
        }
    }

    /**
     * El redondeo no es reversible: el precio crudo del que partió cada artículo no se guardó en
     * ninguna parte. Lo que sí se puede es volver a derivar la cadena sin el eslabón nuevo, que es
     * exactamente lo que hacía 011/014, a partir de las mismas entradas capturadas.
     */
    public function down(): void
    {
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
            $costo = PrecioArticuloCalculator::costoConDescuento(
                (float) $articulo->precio_proveedor,
                (float) $articulo->descuento,
            );
            $costoTotal = PrecioArticuloCalculator::costoTotal($costo, (float) $articulo->costo_goma);

            DB::table('articulos')->where('id', $articulo->id)->update([
                'costo_con_descuento' => $costo,
                'precio_unitario_sin_iva' => PrecioArticuloCalculator::precioVentaSinIva(
                    $costoTotal,
                    (float) ($articulo->utilidad_porcentaje ?? $articulo->utilidad_catalogo),
                ),
            ]);
        }
    }
};
