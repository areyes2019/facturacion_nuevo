<?php

use App\Enums\ClaveConfiguracion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Almacén de configuración global por usuario, clave→valor (ver 014-costo-elaboracion-goma.md).
     *
     * El valor se guarda como texto: es el precio de tener un solo almacén para ajustes de
     * naturaleza distinta (montos hoy; tasas, textos y folios mañana). Quien escribe valida y quien
     * lee interpreta, con las reglas que declara cada caso de ClaveConfiguracion.
     */
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('clave');
            $table->string('valor');
            $table->timestamps();

            $table->unique(['user_id', 'clave']);
        });

        // Se siembran los valores de fábrica de los usuarios existentes para que la pantalla de
        // Configuración muestre montos reales desde el primer día, en vez de campos que parecen
        // vacíos. No es un hueco que tapar: la lectura ya cae al valor por defecto del enum cuando
        // no hay fila.
        $ahora = now();
        $filas = [];

        foreach (DB::table('users')->pluck('id') as $userId) {
            foreach (ClaveConfiguracion::valoresPorDefecto() as $clave => $valor) {
                $filas[] = [
                    'user_id' => $userId,
                    'clave' => $clave,
                    'valor' => $valor,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
        }

        if ($filas !== []) {
            DB::table('configuraciones')->insert($filas);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
