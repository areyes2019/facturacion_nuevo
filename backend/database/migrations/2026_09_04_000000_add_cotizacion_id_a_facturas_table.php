<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El vínculo Cotización↔Factura se voltea: de `cotizaciones.factura_id` (1:1) a
     * `facturas.cotizacion_id` (1:N), para permitir varias facturas por cotización (ver
     * 043-facturas-parciales-cotizacion.md).
     *
     * El sistema está en producción desde el 2026-08-18 (018-despliegue-hostinger.md): a
     * diferencia de historias anteriores, aquí sí hay datos reales que rescatar. Por eso esta
     * migración no solo agrega la columna, también copia el vínculo que ya existía antes de que
     * la migración hermana tire `cotizaciones.factura_id`.
     */
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('cotizacion_id')->nullable()->after('cliente_id')
                ->constrained('cotizaciones')->nullOnDelete();
        });

        DB::table('cotizaciones')
            ->whereNotNull('factura_id')
            ->select('id', 'factura_id')
            ->orderBy('id')
            ->each(function (object $cotizacion) {
                DB::table('facturas')
                    ->where('id', $cotizacion->factura_id)
                    ->update(['cotizacion_id' => $cotizacion->id]);
            });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cotizacion_id');
        });
    }
};
