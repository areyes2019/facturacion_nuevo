<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();

            $table->string('nombre');
            $table->string('modelo');
            $table->string('clave_prod_serv', 8);
            $table->string('clave_unidad', 20);
            $table->string('objeto_imp', 2);
            $table->decimal('precio_unitario_sin_iva', 10, 2);

            $table->timestamps();
            $table->softDeletes();

            // La unicidad del nombre por proveedor (solo entre artículos activos, no eliminados)
            // se valida a nivel de aplicación (Rule::unique + whereNull('deleted_at')), no aquí,
            // para permitir reutilizar el nombre después de un soft delete.
            $table->index(['proveedor_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articulos');
    }
};
