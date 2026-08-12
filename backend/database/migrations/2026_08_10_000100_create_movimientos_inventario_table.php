<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de movimientos de inventario (ver 017-inventario.md). Solo de consulta: no hay
 * endpoints de edición ni borrado, los errores se corrigen con movimientos nuevos.
 *
 * Se escribe **siempre en la misma transacción** que las columnas del artículo, para que nunca
 * pueda quedar una existencia que el historial no explique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('articulo_id')->constrained('articulos')->cascadeOnDelete();

            $table->string('tipo', 10);
            $table->string('motivo', 30);

            // Magnitud del movimiento; la dirección la da `tipo`. En un `ajuste` es la cantidad
            // final capturada por el usuario, no la diferencia.
            $table->unsignedInteger('cantidad');

            // Estado en que quedó el artículo DESPUÉS de aplicar el movimiento. Son los que hacen
            // auditable el historial sin reconstruir nada.
            $table->unsignedInteger('existencia_resultante');
            $table->unsignedInteger('faltante_resultante');

            $table->text('nota')->nullable();

            // Documento que originó el movimiento (OrdenCompra, Factura o Cotizacion); null en los
            // ajustes manuales. Mismo patrón que el `Movimiento` de Tesorería (010).
            $table->nullableMorphs('documentable');

            $table->timestamps();

            // El historial siempre se lee por artículo y en orden.
            $table->index(['articulo_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
