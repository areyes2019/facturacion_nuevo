<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->foreignId('catalogo_id')->nullable()->after('proveedor_id')->constrained('catalogos')->cascadeOnDelete();
            $table->decimal('precio_con_descuento', 10, 2)->default(0)->after('precio_unitario_sin_iva');
        });

        // Migración de datos (ver 009-catalogos.md): cada proveedor que ya tenga artículos recibe
        // un catálogo "General" (descuento 0%) al que se reasignan todos sus artículos existentes,
        // sin perder ningún dato. precio_con_descuento queda igual a precio_unitario_sin_iva porque
        // el catálogo "General" nace con 0% de descuento.
        $proveedorIds = DB::table('articulos')->whereNull('catalogo_id')->distinct()->pluck('proveedor_id');

        foreach ($proveedorIds as $proveedorId) {
            $proveedor = DB::table('proveedores')->where('id', $proveedorId)->first();

            if (! $proveedor) {
                continue;
            }

            $catalogoId = DB::table('catalogos')->insertGetId([
                'user_id' => $proveedor->user_id,
                'proveedor_id' => $proveedorId,
                'nombre' => 'General',
                'descuento' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('articulos')
                ->where('proveedor_id', $proveedorId)
                ->update([
                    'catalogo_id' => $catalogoId,
                    'precio_con_descuento' => DB::raw('precio_unitario_sin_iva'),
                ]);
        }

        Schema::table('articulos', function (Blueprint $table) {
            // El FK debe soltarse antes que el índice: MySQL no permite eliminar un índice
            // todavía referenciado por una foreign key.
            $table->dropForeign(['proveedor_id']);
            $table->dropIndex(['proveedor_id', 'nombre']);
            $table->dropColumn('proveedor_id');
        });

        Schema::table('articulos', function (Blueprint $table) {
            // Solo se ajusta la nulabilidad de la columna, sin volver a declarar la FK (ya existe
            // desde el primer Schema::table de esta migración), para evitar que `change()` intente
            // recrearla.
            $table->unsignedBigInteger('catalogo_id')->nullable(false)->change();
            $table->index(['catalogo_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->foreignId('proveedor_id')->nullable()->after('catalogo_id')->constrained('proveedores')->cascadeOnDelete();
        });

        $catalogos = DB::table('catalogos')->get()->keyBy('id');

        foreach ($catalogos as $catalogo) {
            DB::table('articulos')
                ->where('catalogo_id', $catalogo->id)
                ->update(['proveedor_id' => $catalogo->proveedor_id]);
        }

        Schema::table('articulos', function (Blueprint $table) {
            $table->dropForeign(['catalogo_id']);
            $table->dropIndex(['catalogo_id', 'nombre']);
            $table->dropColumn('catalogo_id');
            $table->dropColumn('precio_con_descuento');
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->unsignedBigInteger('proveedor_id')->nullable(false)->change();
            $table->index(['proveedor_id', 'nombre']);
        });
    }
};
