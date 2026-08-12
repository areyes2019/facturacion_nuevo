<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos fiscales del negocio que emite los documentos (ver 019-formato-pdf-documentos.md).
     *
     * Una sola fila y sin `user_id`: el timbrado usa una única llave de Facturapi tomada del
     * entorno, así que todos los usuarios emiten con el mismo certificado y por tanto con el mismo
     * emisor. Un emisor por usuario permitiría que el PDF dijera un RFC y el CFDI timbrado detrás
     * llevara otro.
     *
     * No entra al almacén clave→valor de 014 —pese a que su docblock lo anticipaba— por tres
     * razones: ese almacén es por usuario y su unicidad se rompe con un `user_id` nulo, su valor
     * es un `string(255)`, y el emisor no es un ajuste suelto sino un registro que se valida y se
     * guarda completo.
     */
    public function up(): void
    {
        Schema::create('emisor', function (Blueprint $table) {
            $table->id();

            // Los tres campos fiscales son nullable, y no es un descuido: el emisor se llena por
            // partes y en el orden que el usuario quiera. Quien sube su logo antes de capturar su
            // RFC está haciendo algo razonable, y con NOT NULL esa fila no se puede crear. El
            // NOT NULL tampoco garantizaría nada —una fila con nombre = '' pasaría igual— y
            // contradiría al resto del diseño: `Emisor::actual()` devuelve una instancia vacía a
            // propósito y `estaCompleto()` existe justamente para preguntar por lo que falta.
            // Quien sí exige los tres es UpdateEmisorRequest, que es donde pertenece la exigencia:
            // el formulario de datos fiscales se guarda completo o no se guarda.
            $table->string('nombre')->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('regimen_fiscal', 3)->nullable();

            // Una línea de texto libre, como en el formato de referencia
            // ("38024, Celaya, Celaya, Guanajuato, MEX"). No se desglosa en calle, número y estado
            // porque el PDF lo imprime en un renglón y el desglose no se usa para nada más.
            $table->string('domicilio')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono', 20)->nullable();

            // Ruta relativa dentro del disco privado; `null` es "sin logo". Los archivos no viven
            // en el disco público: la plantilla los lee con PHP y los incrusta en base64, para no
            // depender de un public/ que en producción no existe (ver 018-despliegue-hostinger.md).
            $table->string('logo_ruta')->nullable();
            $table->string('logo_marca_ruta')->nullable();

            $table->timestamps();
        });

        // Deliberadamente sin sembrado. No hay datos de producción que rescatar, y sembrar el
        // emisor del formato de referencia dejaría el RFC de otra persona en la base de datos de
        // cualquiera que instale el sistema.
    }

    public function down(): void
    {
        Schema::dropIfExists('emisor');
    }
};
