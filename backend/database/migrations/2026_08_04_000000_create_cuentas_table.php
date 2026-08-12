<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('nombre');
            // Enum fijo de backend (efectivo|banco|digital|otro), sin catálogo externo ni tabla
            // propia (ver App\Enums\TipoCuenta y 010-tesoreria.md).
            $table->string('tipo', 10);

            $table->decimal('saldo_inicial', 12, 2)->default(0);
            // Columna persistida y cacheada: se recalcula como saldo_inicial + Σ(movimientos)
            // dentro de la misma transacción que crea, edita o elimina un movimiento, en vez de
            // resolverse con un SUM al vuelo en cada consulta (ver 010-tesoreria.md).
            $table->decimal('saldo_actual', 12, 2)->default(0);

            $table->boolean('activa')->default(true);

            $table->timestamps();

            $table->index(['user_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas');
    }
};
