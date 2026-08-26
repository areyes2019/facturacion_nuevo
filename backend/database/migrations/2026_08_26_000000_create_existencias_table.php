<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revisión del 2026-08-26 de 017-inventario.md: existencia, faltante y umbrales dejan de ser
 * columnas de `articulos` y pasan a una tabla propia, con una fila por artículo que el usuario marcó
 * a mano "en existencias". Un artículo sin fila aquí no es inventario.
 *
 * Los artículos que ya tenían movimiento real bajo el diseño anterior (existencia, faltante o
 * mínimo distintos de su valor por defecto) migran su fila automáticamente, para no perder
 * visibilidad de inventario que ya estaba en marcha. El resto del catálogo no recibe fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('existencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id')->constrained('articulos')->cascadeOnDelete()->unique();

            $table->unsignedInteger('existencia')->default(0);
            $table->unsignedInteger('faltante_pendiente')->default(0);
            $table->unsignedInteger('minimo')->default(0);
            $table->unsignedInteger('maximo')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('articulos')
            ->where(function ($query) {
                $query->where('existencia', '>', 0)
                    ->orWhere('faltante_pendiente', '>', 0)
                    ->orWhere('minimo', '>', 0);
            })
            ->orderBy('id')
            ->select(['id', 'existencia', 'faltante_pendiente', 'minimo', 'maximo'])
            ->get()
            ->each(function ($articulo) {
                DB::table('existencias')->insert([
                    'articulo_id' => $articulo->id,
                    'existencia' => $articulo->existencia,
                    'faltante_pendiente' => $articulo->faltante_pendiente,
                    'minimo' => $articulo->minimo,
                    'maximo' => $articulo->maximo,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn(['existencia', 'faltante_pendiente', 'minimo', 'maximo']);
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->unsignedInteger('existencia')->default(0)->after('precio_unitario_sin_iva');
            $table->unsignedInteger('faltante_pendiente')->default(0)->after('existencia');
            $table->unsignedInteger('minimo')->default(0)->after('faltante_pendiente');
            $table->unsignedInteger('maximo')->nullable()->after('minimo');
        });

        DB::table('existencias')->orderBy('id')->get()->each(function ($existencia) {
            DB::table('articulos')->where('id', $existencia->articulo_id)->update([
                'existencia' => $existencia->existencia,
                'faltante_pendiente' => $existencia->faltante_pendiente,
                'minimo' => $existencia->minimo,
                'maximo' => $existencia->maximo,
            ]);
        });

        Schema::dropIfExists('existencias');
    }
};
