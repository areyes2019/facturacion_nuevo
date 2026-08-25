<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cliente distribuidor (ver 033-precio-distribuidor.md). Columna simple, NOT NULL con default
     * false, mismo patrón que `descuento_permanente` (015): no existe un tercer estado.
     *
     * No se recalcula ninguna cotización ni factura: todos los clientes existentes quedan como "no
     * distribuidor", así que ningún documento ya guardado cambia de valor.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('es_distribuidor')->default(false)->after('descuento_permanente');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('es_distribuidor');
        });
    }
};
