<?php

use App\Models\OrdenTrabajo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Envío directo para distribuidores y dirección de envío (ver
 * 041-envio-domicilio-direccion-y-distribuidor.md).
 *
 * `Envio` deja de depender exclusivamente de `OrdenTrabajo` (038): pasa a una relación polimórfica
 * (`documentable`), la misma técnica que ya usa `OrdenTrabajo::documentable` y
 * `Movimiento::documentable` (010), para que también pueda colgar directamente de una `Cotizacion`.
 *
 * `direccion` queda `nullable` a nivel de columna aunque la validación la exija siempre en los
 * formularios nuevos: los envíos que ya existan en producción no tienen dirección capturada y no se
 * les exige retroactivamente (ver spec, supuesto 2 y 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->string('documentable_type')->nullable()->after('id');
            $table->unsignedBigInteger('documentable_id')->nullable()->after('documentable_type');
            $table->string('direccion')->nullable()->after('telefono_receptor');
            $table->timestamp('entregado_en')->nullable()->after('forma_pago');
        });

        DB::table('envios')->whereNotNull('orden_trabajo_id')->update([
            'documentable_type' => (new OrdenTrabajo)->getMorphClass(),
            'documentable_id' => DB::raw('orden_trabajo_id'),
        ]);

        Schema::table('envios', function (Blueprint $table) {
            $table->dropForeign(['orden_trabajo_id']);
            $table->dropUnique(['orden_trabajo_id']);
            $table->dropColumn('orden_trabajo_id');
            $table->unique(['documentable_type', 'documentable_id']);
        });
    }

    public function down(): void
    {
        Schema::table('envios', function (Blueprint $table) {
            $table->dropUnique(['documentable_type', 'documentable_id']);
            $table->foreignId('orden_trabajo_id')->nullable()->after('id')->constrained('orden_trabajos')->cascadeOnDelete();
        });

        DB::table('envios')
            ->where('documentable_type', (new OrdenTrabajo)->getMorphClass())
            ->update(['orden_trabajo_id' => DB::raw('documentable_id')]);

        Schema::table('envios', function (Blueprint $table) {
            $table->unsignedBigInteger('orden_trabajo_id')->nullable(false)->change();
            $table->unique('orden_trabajo_id');
            $table->dropColumn(['documentable_type', 'documentable_id', 'direccion', 'entregado_en']);
        });
    }
};
