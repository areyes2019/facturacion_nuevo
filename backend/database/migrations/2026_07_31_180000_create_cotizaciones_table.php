<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();

            // Folio interno autoincremental por usuario, numeración propia (independiente del
            // folio de Factura).
            $table->unsignedInteger('folio');

            $table->string('estado', 20)->default('borrador');

            // Descuento global (adicional a los descuentos por línea), mismo esquema que Factura.
            $table->string('descuento_global_tipo', 10)->nullable();
            $table->decimal('descuento_global_valor', 12, 2)->nullable();

            // Totales, siempre recalculados en backend.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_descuento', 12, 2)->default(0);
            $table->decimal('total_iva_16', 12, 2)->default(0);
            $table->decimal('total_iva_0', 12, 2)->default(0);
            $table->decimal('total_exento', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Relación 1:1 opcional con la factura resultante de convertir esta cotización.
            $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();

            $table->timestamps();

            $table->unique(['user_id', 'folio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotizaciones');
    }
};
