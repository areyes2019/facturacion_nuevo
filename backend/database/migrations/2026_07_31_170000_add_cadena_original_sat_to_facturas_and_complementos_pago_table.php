<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Descubierto verificando una respuesta real de facturapi.io: `stamp.complement_string`
     * sí trae la cadena original del SAT (contradice el supuesto original de que esa respuesta
     * no la incluía, ver 007-facturacion.md).
     */
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->text('cadena_original_sat')->nullable()->after('sello_sat');
        });

        Schema::table('complementos_pago', function (Blueprint $table) {
            $table->text('cadena_original_sat')->nullable()->after('sello_cfdi');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('cadena_original_sat');
        });

        Schema::table('complementos_pago', function (Blueprint $table) {
            $table->dropColumn('cadena_original_sat');
        });
    }
};
