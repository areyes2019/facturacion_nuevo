<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sin `tipo` ni `forma_pago`, a diferencia de `cotizacion_pagos`: un pedido admite cuantos pagos
 * haga falta y el estado se deriva de la suma, así que etiquetar cada uno como "anticipo" o "saldo"
 * no decide nada. Lo que sí decide es la cuenta a la que entra el dinero (ver 010-tesoreria.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();

            $table->date('fecha_pago');
            $table->decimal('monto', 12, 2);
            $table->foreignId('cuenta_id')->nullable()->constrained('cuentas')->nullOnDelete();

            // Lo pone en true únicamente el cobro que dispara el escaneo del QR. Es lo que permite
            // que "Deshacer" sepa qué pago borrar sin adivinar por monto o por fecha.
            $table->boolean('automatico')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_pagos');
    }
};
