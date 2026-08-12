<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizacion_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();

            // Informativo, no CFDI (ver 008-cotizaciones.md, supuesto #8); permite historial de
            // múltiples pagos por cotización (anticipo, saldo, o pago total en un solo registro).
            $table->string('tipo', 15);
            $table->date('fecha_pago');
            $table->decimal('monto', 12, 2);
            $table->string('forma_pago', 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizacion_pagos');
    }
};
