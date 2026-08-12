<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('nombre_comercial');
            $table->string('nombre_contacto')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->boolean('tiene_ordenes_activas')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // La unicidad del RFC por usuario (solo entre proveedores activos, no eliminados)
            // se valida a nivel de aplicación (Rule::unique + whereNull('deleted_at')), no aquí,
            // para permitir reutilizar un RFC después de un soft delete.
            $table->index(['user_id', 'rfc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
