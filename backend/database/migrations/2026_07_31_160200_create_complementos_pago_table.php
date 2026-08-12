<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complementos_pago', function (Blueprint $table) {
            $table->id();
            // Relación 1:1: una factura PPD no puede tener más de un complemento de pago
            // en esta historia (sin parcialidades múltiples).
            $table->foreignId('factura_id')->unique()->constrained('facturas')->cascadeOnDelete();

            $table->date('fecha_pago');
            $table->decimal('monto', 12, 2);
            $table->string('forma_pago', 2);

            $table->string('estado', 10)->default('pendiente');
            $table->string('facturapi_invoice_id')->nullable();
            $table->uuid('uuid_fiscal')->nullable();
            $table->text('sello_cfdi')->nullable();
            $table->text('error_timbrado')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complementos_pago');
    }
};
