<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cotizacion_lineas', function (Blueprint $table) {
            $table->decimal('costo_unitario', 10, 2)->nullable()->after('precio_unitario');
        });

        Schema::table('pedido_lineas', function (Blueprint $table) {
            $table->decimal('costo_unitario', 10, 2)->nullable()->after('precio_unitario');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cotizacion_lineas', function (Blueprint $table) {
            $table->dropColumn('costo_unitario');
        });

        Schema::table('pedido_lineas', function (Blueprint $table) {
            $table->dropColumn('costo_unitario');
        });
    }
};
