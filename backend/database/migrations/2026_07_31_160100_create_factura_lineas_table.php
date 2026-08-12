<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factura_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('facturas')->cascadeOnDelete();
            $table->foreignId('articulo_id')->nullable()->constrained('articulos')->nullOnDelete();

            $table->unsignedInteger('cantidad');

            // Copias propias de la línea, precargadas del artículo pero editables in-place
            // (desacopladas del catálogo tras agregarse).
            $table->string('descripcion');
            $table->string('modelo');
            $table->decimal('precio_unitario', 10, 2);

            $table->string('descuento_tipo', 10)->nullable();
            $table->decimal('descuento_valor', 12, 2)->nullable();

            $table->string('tasa_iva', 10);

            // Calculados en backend: importe neto (sin IVA, mostrado como "Total" de la fila)
            // e IVA de la línea.
            $table->decimal('importe', 12, 2);
            $table->decimal('iva_importe', 12, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factura_lineas');
    }
};
