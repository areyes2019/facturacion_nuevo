<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. catalogos gana utilidad_porcentaje decimal(5,2) con default 0 (ver 011).
        Schema::table('catalogos', function (Blueprint $table) {
            $table->decimal('utilidad_porcentaje', 5, 2)->default(0)->after('descuento');
        });

        // 2. articulos gana precio_proveedor y utilidad_porcentaje nullable.
        Schema::table('articulos', function (Blueprint $table) {
            $table->decimal('precio_proveedor', 10, 2)->default(0)->after('objeto_imp');
            $table->decimal('utilidad_porcentaje', 5, 2)->nullable()->after('precio_proveedor');
        });

        // 3. articulos.precio_con_descuento se renombra a costo_con_descuento.
        Schema::table('articulos', function (Blueprint $table) {
            $table->renameColumn('precio_con_descuento', 'costo_con_descuento');
        });

        // 4. Cada artículo existente toma su precio_unitario_sin_iva actual como precio_proveedor
        //    (su precio actual pasa a interpretarse como precio de lista del proveedor) y queda con
        //    utilidad_porcentaje en NULL (hereda del catálogo).
        DB::table('articulos')->update([
            'precio_proveedor' => DB::raw('precio_unitario_sin_iva'),
            'utilidad_porcentaje' => null,
        ]);

        // 5. Se recalcula la cadena completa hacia adelante. Con 0% de utilidad en los catálogos
        //    (default recién agregado), el costo con descuento = precio_proveedor * (1 - descuento/100)
        //    y el precio de venta queda igual al costo con descuento (utilidad 0%).
        //
        //    Se usa una subconsulta correlacionada (no un JOIN) para leer el descuento del catálogo:
        //    SQLite no permite referenciar columnas de la tabla unida en el SET de un UPDATE con
        //    JOIN, y además evalúa todas las expresiones del SET contra la fila OLD (a diferencia de
        //    MySQL, que las evalúa en orden). Por eso ambas columnas se calculan de forma
        //    independiente a partir de precio_proveedor y del descuento del catálogo.
        //
        //    "100.0" (no "100"): en SQLite el operador "/" entre dos literales enteros trunca a
        //    división entera; forzar un literal decimal evita ese truncamiento en ambos motores.
        DB::table('articulos')->update([
            'costo_con_descuento' => DB::raw(
                'ROUND(precio_proveedor * (1 - (SELECT descuento FROM catalogos WHERE catalogos.id = articulos.catalogo_id) / 100.0), 2)'
            ),
            'precio_unitario_sin_iva' => DB::raw(
                'ROUND(precio_proveedor * (1 - (SELECT descuento FROM catalogos WHERE catalogos.id = articulos.catalogo_id) / 100.0), 2)'
            ),
        ]);
    }

    public function down(): void
    {
        // Revertir la cadena: el precio de venta vuelve a ser el precio de lista capturado.
        DB::table('articulos')->update([
            'precio_unitario_sin_iva' => DB::raw('precio_proveedor'),
        ]);

        Schema::table('articulos', function (Blueprint $table) {
            $table->renameColumn('costo_con_descuento', 'precio_con_descuento');
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn('precio_proveedor');
            $table->dropColumn('utilidad_porcentaje');
        });

        Schema::table('catalogos', function (Blueprint $table) {
            $table->dropColumn('utilidad_porcentaje');
        });
    }
};
