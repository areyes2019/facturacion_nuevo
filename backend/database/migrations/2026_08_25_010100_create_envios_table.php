<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envío a domicilio de una Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md).
 *
 * 1 a 1 con `orden_trabajos`: una orden admite como máximo un envío, y un envío no se edita ni se
 * borra una vez creado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_trabajo_id')->unique()->constrained('orden_trabajos')->cascadeOnDelete();

            $table->string('nombre_receptor', 150);
            $table->string('telefono_receptor', 30);

            $table->date('fecha_recepcion');
            $table->string('hora_recepcion', 5);

            // a/b/c — App\Enums\TarifaEnvio.
            $table->string('tarifa', 1);

            // Copia congelada del monto configurado de la tarifa en el momento de crear el envío
            // (ver App\Enums\ClaveConfiguracion): un cambio posterior en Configuración no altera
            // envíos ya creados.
            $table->decimal('monto', 10, 2);

            // prepagado/por_cobrar — App\Enums\FormaPagoEnvio.
            $table->string('forma_pago', 12);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios');
    }
};
