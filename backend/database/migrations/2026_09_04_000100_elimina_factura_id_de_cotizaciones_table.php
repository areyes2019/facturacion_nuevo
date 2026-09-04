<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Segunda mitad del volteo del vínculo (ver la migración hermana
     * `..._add_cotizacion_id_a_facturas_table`, que copia el dato antes de que esta lo tire).
     */
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factura_id');
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();
        });
    }
};
