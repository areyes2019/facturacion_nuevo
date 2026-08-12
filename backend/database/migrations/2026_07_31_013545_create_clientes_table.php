<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Fiscales (obligatorios)
            $table->string('rfc', 13);
            $table->string('razon_social');
            $table->string('regimen_fiscal', 3);
            $table->string('codigo_postal_fiscal', 5);

            // Comerciales (opcionales)
            $table->string('nombre_comercial')->nullable();
            $table->string('correo_contacto')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('direccion_comercial')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // La unicidad del RFC por usuario (solo entre clientes activos, no eliminados)
            // se valida a nivel de aplicación (Rule::unique + whereNull('deleted_at')), no aquí,
            // para permitir reutilizar un RFC después de un soft delete.
            $table->index(['user_id', 'rfc']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
