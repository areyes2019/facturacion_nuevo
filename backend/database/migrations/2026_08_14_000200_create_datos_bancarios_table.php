<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cuentas bancarias que el negocio le da al cliente para que pague
     * (ver 026-datos-bancarios-cotizacion.md).
     *
     * **No son las Cuentas de Tesorería de 010.** Aquéllas son dónde está el dinero —tienen saldo y
     * reciben movimientos—; éstas son lo que se imprime en la cotización para cobrar. Un banco de
     * aquí puede no tener Cuenta de Tesorería, y la caja de efectivo nunca sería un dato bancario.
     *
     * Tabla propia y no una clave del almacén clave→valor de 014: ese almacén guarda una casilla
     * con un valor, y aquí hacen falta cinco datos que van juntos, repetidos N veces y con orden
     * propio. Meterlos ahí obligaría a inventar claves numeradas (`banco_1_clabe`).
     */
    public function up(): void
    {
        Schema::create('datos_bancarios', function (Blueprint $table) {
            $table->id();

            // Sin `user_id`, misma excepción y misma razón que `emisor` en 019: el negocio que
            // emite es uno solo para toda la instalación. Partirlos por usuario dejaría a dos
            // usuarios cobrando a cuentas distintas bajo el mismo RFC.
            $table->string('nombre_banco', 100);
            $table->string('beneficiario', 150)->nullable();

            // Texto y no entero, aunque solo contengan dígitos: un número de cuenta puede empezar
            // con cero y como entero `0123456789` se vuelve `123456789`, que es otra cuenta. Nunca
            // se suman ni se comparan; son etiquetas, no cantidades.
            $table->string('numero_cuenta', 20)->nullable();
            $table->string('tarjeta', 16)->nullable();
            $table->string('clabe', 18)->nullable();

            // Apagado, el banco sigue guardado pero deja de imprimirse. El caso real no es "ya no
            // uso este banco nunca" sino "este mes cobro por el otro", y borrarlo obligaría a
            // recapturar 18 dígitos de CLABE al volver.
            $table->boolean('visible_en_cotizaciones')->default(true);

            // Posición en la lista y en el PDF. La asigna el sistema (un alta entra al final); el
            // usuario la cambia arrastrando, no capturándola.
            $table->unsignedInteger('orden')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datos_bancarios');
    }
};
