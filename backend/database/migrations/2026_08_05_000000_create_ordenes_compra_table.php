<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();

            // Folio interno autoincremental por usuario, numeración propia e independiente de la
            // de Factura y Cotización. Se presenta como OC-00015.
            $table->unsignedInteger('folio');

            $table->string('estado', 20)->default('borrador');

            // Informativas: se imprimen en el PDF y no disparan alertas ni cambios de estado.
            $table->date('fecha_entrega_esperada')->nullable();
            $table->text('observaciones')->nullable();

            // Descuento global (adicional a los descuentos por línea), mismo esquema que
            // Factura/Cotización.
            $table->string('descuento_global_tipo', 10)->nullable();
            $table->decimal('descuento_global_valor', 12, 2)->nullable();

            // Totales, siempre recalculados en backend.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_descuento', 12, 2)->default(0);
            $table->decimal('total_iva_16', 12, 2)->default(0);
            $table->decimal('total_iva_0', 12, 2)->default(0);
            $table->decimal('total_exento', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Pago de contado: único y por el total de la orden, así que vive aquí y no en una
            // tabla de pagos (a diferencia de CotizacionPago, que sí necesita historial porque un
            // cliente paga en tiempos). Ambas columnas en null = orden no pagada; ver
            // 012-ordenes-compra.md, adición técnica 35.
            $table->foreignId('cuenta_id')->nullable()->constrained('cuentas')->nullOnDelete();
            $table->date('fecha_pago')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'folio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_compra');
    }
};
