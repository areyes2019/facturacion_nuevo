<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Descuento permanente por cliente (ver 015-descuento-permanente-cliente.md).
     *
     * Las dos columnas son NOT NULL con default 0.00, no nullable: NULL y 0.00 significarían
     * exactamente lo mismo en todos los cálculos y en todas las pantallas, a diferencia de
     * `tamano_goma` en 014, donde NULL sí era un estado distinto ("no lleva goma").
     *
     * No se recalcula ningún documento ni ningún precio: todos los clientes quedan en 0.00 y con
     * eso la aritmética de cotizaciones y facturas ya guardadas es idéntica a la de antes.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->decimal('descuento_permanente', 5, 2)->default(0)->after('direccion_comercial');
        });

        // Copia congelada del descuento que tenía el cliente al capturar la cotización. Es contexto
        // informativo para explicar de dónde salieron los porcentajes precargados; la fuente de
        // verdad del cálculo sigue siendo el descuento de cada línea.
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->decimal('descuento_cliente_porcentaje', 5, 2)->default(0)->after('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('descuento_permanente');
        });

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('descuento_cliente_porcentaje');
        });
    }
};
