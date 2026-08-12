<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cuenta_id')->constrained('cuentas')->cascadeOnDelete();

            $table->string('tipo', 15);
            $table->decimal('monto', 12, 2);
            // Fecha real en que ocurrió el movimiento, no la de captura; día calendario en la zona
            // horaria del negocio (ver 010-tesoreria.md).
            $table->date('fecha');
            $table->string('concepto');

            // Documento origen: relación polimórfica nullable que hoy solo apunta a CotizacionPago
            // y queda lista para futuros módulos (Órdenes de Compra, Ventas) sin otra migración.
            // Su presencia es la que hace al movimiento "automático" (solo lectura desde Tesorería).
            $table->nullableMorphs('documentable');

            // Identificador compartido por las dos filas que componen una transferencia (una resta
            // en la cuenta origen, otra suma en la destino); se editan o eliminan siempre juntas.
            $table->uuid('transferencia_id')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'fecha']);
            $table->index('transferencia_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos');
    }
};
