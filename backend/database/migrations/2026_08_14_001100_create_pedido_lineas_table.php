<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();

            // `articulo_id` nulo es la **línea libre**: algo que se vendió en mostrador y no está en
            // el catálogo. No mueve inventario (InventarioService ya descarta las líneas sin
            // artículo) y al facturarse se timbra con claves SAT genéricas.
            $table->foreignId('articulo_id')->nullable()->constrained('articulos')->nullOnDelete();

            $table->unsignedInteger('cantidad');

            $table->string('descripcion');
            // Nullable, a diferencia de cotizacion_lineas: una línea libre no tiene modelo de
            // catálogo que copiar.
            $table->string('modelo')->nullable();
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
        Schema::dropIfExists('pedido_lineas');
    }
};
