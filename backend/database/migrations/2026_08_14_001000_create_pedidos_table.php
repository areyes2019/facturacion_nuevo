<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venta de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * Documento propio y no una variante de `cotizaciones` porque el cliente que entra a comprar no
 * tiene RFC —y `cotizaciones.cliente_id` es obligatorio hacia el catálogo fiscal—, porque aquí el
 * dinero llega antes que el documento, y porque la entrega se cierra escaneando una etiqueta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Folio propio por usuario, independiente del de Factura y del de Cotizacion. Es el
            // "No. de ticket" que se imprime en el ticket y en la etiqueta.
            $table->unsignedInteger('folio');

            // El cliente de mostrador vive dentro del pedido, sin FK a `clientes`: entrega nombre,
            // teléfono y correo, y muchas veces nunca pedirá factura. Si después se autofactura, es
            // ahí donde nace su ficha fiscal.
            $table->string('cliente_nombre', 150);
            $table->string('cliente_telefono', 30);
            $table->string('cliente_correo')->nullable();

            $table->string('estado', 20)->default('pendiente');

            $table->string('descuento_global_tipo', 10)->nullable();
            $table->decimal('descuento_global_valor', 12, 2)->nullable();

            // Totales, siempre recalculados en backend (mismo algoritmo que Factura y Cotizacion).
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_descuento', 12, 2)->default(0);
            $table->decimal('total_iva_16', 12, 2)->default(0);
            $table->decimal('total_iva_0', 12, 2)->default(0);
            $table->decimal('total_exento', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            // Momento exacto en que el escaneo del QR cerró el pedido. Es lo que hace posible la
            // ventana del "Deshacer": sin la marca de tiempo no habría con qué acotarla.
            $table->timestamp('entregado_en')->nullable();

            // Ruta del ticket JPG dentro del disco privado. Solo la escribe TicketPedidoService.
            $table->string('ticket_ruta')->nullable();

            // Clave larga al azar del enlace público de autofacturación. Nace cuando el pedido
            // queda totalmente pagado; sin ella el enlace sería adivinable probando ids.
            $table->string('autofactura_token', 64)->nullable()->unique();
            $table->text('autofactura_error')->nullable();

            $table->foreignId('factura_id')->nullable()->constrained('facturas')->nullOnDelete();

            $table->timestamps();

            $table->unique(['user_id', 'folio']);
            // Sostiene la sugerencia de "este teléfono ya compró antes" al capturar un pedido.
            $table->index(['user_id', 'cliente_telefono']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
