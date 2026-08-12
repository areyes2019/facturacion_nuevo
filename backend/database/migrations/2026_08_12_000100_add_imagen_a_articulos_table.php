<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imagen del artículo (ver 020-imagenes-articulos.md).
     *
     * Guarda la ruta relativa del archivo dentro del disco privado, mismo criterio y mismo sufijo
     * que `emisor.logo_ruta` en 019. `null` significa "sin imagen", que no es un estado de error:
     * la ficha muestra su marcador y sigue.
     *
     * No hay nada que sembrar: los artículos existentes quedan sin imagen hasta que se suba una.
     */
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->string('imagen_ruta')->nullable()->after('tamano_goma');
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn('imagen_ruta');
        });
    }
};
