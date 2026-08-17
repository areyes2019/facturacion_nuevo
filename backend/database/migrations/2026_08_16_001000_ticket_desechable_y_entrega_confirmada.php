<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Revisión del 2026-08-16 de 027-venta-mostrador-ticket.md.
 *
 * Dos cambios de forma que salen de dos decisiones de fondo:
 *
 * - **El ticket ya no se guarda.** Se dibuja cada vez que se pide y se desecha, así que la columna
 *   que apuntaba al archivo sobra y los tickets que quedaron en el disco son basura. La imagen no
 *   es un registro: se vuelve a dibujar idéntica desde los datos del pedido cuando haga falta.
 * - **La entrega ya no cobra sola.** `automatico` nombraba un cobro que el sistema hacía sin
 *   preguntar, y ese cobro ya no existe: ahora el usuario confirma y elige la cuenta. El dato que
 *   sí sigue importando es *cuándo* entró el pago —al capturarlo o al cerrar la venta—, así que la
 *   columna se queda con el nombre que describe eso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('ticket_ruta');
        });

        Schema::table('pedido_pagos', function (Blueprint $table) {
            $table->renameColumn('automatico', 'registrado_al_entregar');
        });

        // Los tickets dibujados por la primera versión ya no los lee nadie.
        Storage::disk('local')->deleteDirectory('pedidos/tickets');
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('ticket_ruta')->nullable()->after('entregado_en');
        });

        Schema::table('pedido_pagos', function (Blueprint $table) {
            $table->renameColumn('registrado_al_entregar', 'automatico');
        });
    }
};
