<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_compra_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $table->foreignId('articulo_id')->nullable()->constrained('articulos')->nullOnDelete();

            $table->unsignedInteger('cantidad');

            // Copias propias de la línea, precargadas del artículo pero editables in-place mientras
            // la orden no esté pagada (ver 012-ordenes-compra.md, supuesto #8). A diferencia de
            // Factura/Cotización, `precio_unitario` se precarga con el COSTO del artículo
            // (costo_con_descuento), no con su precio de venta.
            $table->string('descripcion');
            $table->string('modelo');
            $table->decimal('precio_unitario', 10, 2);

            $table->string('descuento_tipo', 10)->nullable();
            $table->decimal('descuento_valor', 12, 2)->nullable();

            $table->string('tasa_iva', 10);

            $table->decimal('importe', 12, 2);
            $table->decimal('iva_importe', 12, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_compra_lineas');
    }
};
