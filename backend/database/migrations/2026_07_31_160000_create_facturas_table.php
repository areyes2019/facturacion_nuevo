<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();

            // Folio interno autoincremental por usuario (no el folio fiscal, ver
            // facturapi_folio más abajo); asignado desde la creación, antes de timbrar.
            $table->unsignedInteger('folio');

            $table->string('estado', 20)->default('pendiente');

            // Datos fiscales de cabecera.
            $table->string('uso_cfdi', 4);
            $table->string('forma_pago', 2);
            $table->string('metodo_pago', 3);
            $table->string('moneda', 3)->default('MXN');
            $table->char('tipo_comprobante', 1)->default('I');

            // Descuento global (adicional a los descuentos por línea).
            $table->string('descuento_global_tipo', 10)->nullable();
            $table->decimal('descuento_global_valor', 12, 2)->nullable();

            // Totales, siempre recalculados en backend.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_descuento', 12, 2)->default(0);
            $table->decimal('total_iva_16', 12, 2)->default(0);
            $table->decimal('total_iva_0', 12, 2)->default(0);
            $table->decimal('total_exento', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Sellos/timbrado (nulos hasta timbrarse), mapeados de la respuesta de facturapi.io.
            $table->string('facturapi_invoice_id')->nullable();
            $table->uuid('uuid_fiscal')->nullable();
            $table->string('facturapi_serie', 10)->nullable();
            $table->unsignedInteger('facturapi_folio')->nullable();
            $table->text('sello_cfdi')->nullable();
            $table->text('sello_sat')->nullable();
            $table->string('no_certificado_sat', 20)->nullable();
            $table->timestamp('fecha_timbrado')->nullable();
            $table->string('version_comprobante', 10)->nullable();

            $table->text('error_timbrado')->nullable();

            // Cancelación.
            $table->string('motivo_cancelacion', 2)->nullable();
            $table->foreignId('factura_sustituta_id')->nullable()->constrained('facturas')->nullOnDelete();
            $table->timestamp('fecha_cancelacion')->nullable();
            $table->string('estado_cancelacion', 10)->nullable();

            $table->timestamps();

            // El folio interno es único por usuario (no global).
            $table->unique(['user_id', 'folio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas');
    }
};
