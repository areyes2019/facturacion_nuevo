<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Icono del banco que se imprime junto a su nombre en la cotización
     * (ver 026-datos-bancarios-cotizacion.md).
     *
     * Ruta relativa dentro del disco privado; `null` es "sin logo". Los archivos no viven en el
     * disco público por lo mismo que los logos del emisor y las imágenes de artículo: en producción
     * `storage:link` no funciona y el docroot se vacía en cada despliegue
     * (ver 018-despliegue-hostinger.md, 020-imagenes-articulos.md).
     */
    public function up(): void
    {
        Schema::table('datos_bancarios', function (Blueprint $table) {
            $table->string('logo_ruta')->nullable()->after('clabe');
        });
    }

    public function down(): void
    {
        Schema::table('datos_bancarios', function (Blueprint $table) {
            $table->dropColumn('logo_ruta');
        });
    }
};
