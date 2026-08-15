<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto de los datos bancarios con los que salió cada cotización
     * (ver 026-datos-bancarios-cotizacion.md).
     *
     * El PDF se regenera cada vez que se abre o se reenvía, y es un documento que ya salió del
     * sistema: el cliente lo tiene en su correo. Leyendo los datos vigentes, cambiar de banco en
     * marzo haría que la cotización de enero se reimprimiera con la cuenta nueva y el papel que
     * tiene el cliente dejaría de coincidir con lo que ve el usuario, sin que nada avise.
     *
     * `null` significa "cotización anterior a esta historia" y se imprime sin bloque, igual que
     * `[]`. Deliberadamente **sin relleno hacia atrás**: no hay datos de producción que rescatar
     * (ver 018-despliegue-hostinger.md) y rellenar con los datos de hoy inventaría una foto que
     * nunca se tomó.
     */
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->json('datos_bancarios')->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('datos_bancarios');
        });
    }
};
