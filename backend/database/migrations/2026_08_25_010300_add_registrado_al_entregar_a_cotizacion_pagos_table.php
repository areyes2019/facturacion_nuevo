<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue el pago que cerró la entrega (cobrado al escanear el QR) del capturado a mano —
 * mismo campo y mismo papel que `pedido_pagos.registrado_al_entregar` (027), necesario para que
 * "Deshacer" de la nueva entrega de Cotización (ver 038) sepa si esa entrega cobró algo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->boolean('registrado_al_entregar')->default(false)->after('cuenta_id');
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->dropColumn('registrado_al_entregar');
        });
    }
};
