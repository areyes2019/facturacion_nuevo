<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotización gana QR de entrega propio, igual que Pedido (ver 038-produccion-ordenes-trabajo.md).
 * `entregado_en` es el momento exacto en que el escaneo cerró la cotización — mismo campo y mismo
 * papel que `pedidos.entregado_en` (027), necesario para acotar la ventana de "Deshacer".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->timestamp('entregado_en')->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('entregado_en');
        });
    }
};
