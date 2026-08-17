<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centavos que suben el total del documento al peso cerrado (ver
 * specs/030-total-al-peso-cerrado.md). Solo lo llevan los tres documentos que ve el cliente;
 * una orden de compra paga lo que cobra el proveedor y no redondea.
 *
 * No recalcula nada: los documentos existentes se quedan con su total, porque una factura timbrada
 * ya está estampada ante el SAT y una cotización o un pedido viejos toman el ajuste la próxima vez
 * que se guarden.
 */
return new class extends Migration
{
    private const TABLAS = ['facturas', 'cotizaciones', 'pedidos'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->decimal('ajuste_al_peso', 4, 2)->default(0)->after('total_exento');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('ajuste_al_peso');
            });
        }
    }
};
