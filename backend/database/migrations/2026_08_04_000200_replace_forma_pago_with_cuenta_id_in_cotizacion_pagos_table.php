<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `forma_pago` (catálogo SAT c_FormaPago) era un dato meramente informativo en CotizacionPago: una
 * cotización no es un CFDI, así que ese valor nunca se timbraba. Se reemplaza por `cuenta_id`, que
 * sí tiene efecto real — es la cuenta de Tesorería donde entra el dinero (ver 010-tesoreria.md).
 *
 * Esto no toca el `forma_pago` de ComplementoPago (007), que sí es un dato fiscal del CFDI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            // Nullable en la base de datos únicamente para poder migrar pagos ya registrados antes
            // de que Tesorería existiera (no tienen ninguna cuenta a la cual apuntar). Para todo
            // pago nuevo es obligatoria: la regla vive en CotizacionPagoRequest.
            $table->foreignId('cuenta_id')->nullable()->after('monto')->constrained('cuentas')->nullOnDelete();
        });

        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->dropColumn('forma_pago');
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->string('forma_pago', 2)->default('99');
        });

        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuenta_id');
        });
    }
};
