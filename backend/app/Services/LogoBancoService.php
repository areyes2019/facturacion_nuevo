<?php

namespace App\Services;

use App\Models\DatoBancario;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puerta única del icono de cada banco (ver 026-datos-bancarios-cotizacion.md).
 *
 * Hermano de `ImagenArticuloService`: la misma forma, el mismo disco privado y el mismo
 * `ProcesadorImagen`, con otro tamaño. Lo propio de aquí es que el archivo se reduce hasta ser un
 * icono —64 puntos de lado largo— **al guardarlo**, no al imprimirlo: guardar el original y
 * encogerlo en el PDF metería el archivo completo en cada cotización y engordaría cada correo por
 * cada banco.
 */
class LogoBancoService
{
    /**
     * Lado largo máximo, en puntos. Se imprime a 5 mm de alto; 64 puntos dan margen de sobra para
     * que se vea nítido en papel sin que el archivo pese.
     */
    public const LADO_MAXIMO = 64;

    public function __construct(private readonly ProcesadorImagen $procesador) {}

    /**
     * Guarda el archivo como logo del banco y borra el que tuviera antes.
     *
     * Lanza `RuntimeException` con un motivo legible si no es una imagen de un formato aceptado.
     */
    public function guardar(DatoBancario $banco, string $rutaOrigen): string
    {
        $contenido = $this->procesador->procesar($rutaOrigen, self::LADO_MAXIMO);

        $anterior = $banco->logo_ruta;
        $ruta = DatoBancario::DIRECTORIO_LOGOS.'/'.$banco->id.'-'.Str::lower(Str::random(8)).'.webp';

        Storage::disk('local')->put($ruta, $contenido);

        $banco->forceFill(['logo_ruta' => $ruta])->save();

        // Se borra después de guardar el nuevo y no antes: si el guardado falla, el banco se queda
        // con el logo que ya tenía en vez de perder los dos.
        $this->borrarArchivo($anterior);

        return $ruta;
    }

    /**
     * Quita el logo del banco y borra su archivo. No falla si el banco no tenía ninguno.
     *
     * Ojo con la asimetría, que es deliberada: quitar el logo **sí** borra el archivo, pero
     * eliminar el banco entero **no** (ver `DatoBancarioController::destroy`). Quien quita el logo
     * está diciendo "este archivo ya no va"; quien borra el banco no está diciendo nada sobre las
     * cotizaciones viejas que lo imprimen.
     */
    public function eliminar(DatoBancario $banco): void
    {
        $anterior = $banco->logo_ruta;

        $banco->forceFill(['logo_ruta' => null])->save();

        $this->borrarArchivo($anterior);
    }

    private function borrarArchivo(?string $ruta): void
    {
        if (filled($ruta)) {
            Storage::disk('local')->delete($ruta);
        }
    }
}
