<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Existencia, faltante y umbrales de reposición viven en la ficha del artículo, no en una tabla
 * aparte: la lista de inventario **es** la lista de artículos con cuatro columnas más (ver
 * 017-inventario.md). Todos los artículos existentes arrancan en 0; no hay carga retroactiva de
 * órdenes ya recibidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            // La existencia nunca es negativa: una salida que la excede la deja en 0 y acumula el
            // sobrante en `faltante_pendiente`, que es un descuadre de registro, no una deuda con
            // un cliente.
            $table->unsignedInteger('existencia')->default(0)->after('precio_unitario_sin_iva');
            $table->unsignedInteger('faltante_pendiente')->default(0)->after('existencia');

            // `minimo` en 0 significa "no me avises de este artículo". `maximo` es el techo al que
            // se rellena; si es null, el techo de la sugerencia es el propio mínimo.
            $table->unsignedInteger('minimo')->default(0)->after('faltante_pendiente');
            $table->unsignedInteger('maximo')->nullable()->after('minimo');
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn(['existencia', 'faltante_pendiente', 'minimo', 'maximo']);
        });
    }
};
