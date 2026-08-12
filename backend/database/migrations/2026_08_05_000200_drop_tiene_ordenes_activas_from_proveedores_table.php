<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 005 sembró `tiene_ordenes_activas` como columna booleana esperando al módulo de Órdenes de
 * compra. Ahora que existe, el dato se deriva por consulta (`exists()` sobre órdenes en estado
 * distinto de `recibida`) en vez de mantener una columna sincronizada a mano en cada alta, cambio
 * de estado y borrado de orden — la clase de dato que se desincroniza en silencio y solo se nota
 * cuando un proveedor no se deja borrar sin razón aparente (ver 012-ordenes-compra.md, adición
 * técnica 37).
 *
 * `ProveedorResource` sigue exponiendo el mismo campo y el `409` del DELETE conserva su mensaje:
 * solo cambia de dónde sale el booleano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('tiene_ordenes_activas');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->boolean('tiene_ordenes_activas')->default(false);
        });
    }
};
