<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md).
 *
 * Cuelga de un `Pedido` o de una `Cotizacion` a través de una relación polimórfica —la misma técnica
 * que ya usa `movimientos.documentable` (010-tesoreria)— para no repetir dos columnas casi siempre
 * vacías. No guarda cliente, producto ni precios propios: todo eso se lee del documento origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orden_trabajos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Folio propio, consecutivo por usuario, independiente del folio del documento origen.
            $table->unsignedInteger('folio');

            // Documento origen: Pedido o Cotizacion. Único por documento — una orden como máximo.
            $table->morphs('documentable');

            $table->string('estado', 20)->default('pendiente');

            // Solo la escribe ImagenOrdenTrabajoService (ver 020-imagenes-articulos.md).
            $table->string('imagen_ruta')->nullable();

            $table->text('observaciones')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'folio']);
            $table->unique(['documentable_type', 'documentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orden_trabajos');
    }
};
