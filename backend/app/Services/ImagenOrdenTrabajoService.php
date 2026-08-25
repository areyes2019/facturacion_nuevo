<?php

namespace App\Services;

use App\Models\OrdenTrabajo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puerta única por la que entra la imagen de diseño de una Orden de Trabajo (ver
 * 038-produccion-ordenes-trabajo.md). Copia del patrón de `ImagenArticuloService` (020): mismo
 * `ProcesadorImagen`, mismo redimensionado a 1200 px de lado largo, mismo formato WEBP, mismo
 * nombre generado por el sistema.
 */
class ImagenOrdenTrabajoService
{
    public const LADO_MAXIMO = 1200;

    public function __construct(private readonly ProcesadorImagen $procesador) {}

    /**
     * Guarda el archivo como imagen de la orden y borra la que tuviera antes.
     *
     * Lanza `RuntimeException` con un motivo legible si el archivo no es una imagen de un formato
     * aceptado, mismo criterio que `ImagenArticuloService::guardar()`.
     */
    public function guardar(OrdenTrabajo $orden, string $rutaOrigen): string
    {
        $contenido = $this->procesador->procesar($rutaOrigen, self::LADO_MAXIMO);

        $anterior = $orden->imagen_ruta;
        $ruta = OrdenTrabajo::DIRECTORIO_IMAGENES.'/'.$orden->id.'-'.Str::lower(Str::random(8)).'.webp';

        Storage::disk('local')->put($ruta, $contenido);

        $orden->forceFill(['imagen_ruta' => $ruta])->save();

        // Se borra después de guardar la nueva y no antes: si el guardado falla, la orden se queda
        // con la imagen que ya tenía en vez de perder las dos.
        $this->borrarArchivo($anterior);

        return $ruta;
    }

    /**
     * Quita la imagen de la orden y borra su archivo. No falla si la orden no tenía ninguna.
     */
    public function eliminar(OrdenTrabajo $orden): void
    {
        $anterior = $orden->imagen_ruta;

        $orden->forceFill(['imagen_ruta' => null])->save();

        $this->borrarArchivo($anterior);
    }

    /**
     * Contenido binario de la imagen, o `null` si no hay o el archivo ya no está en disco.
     */
    public function contenido(OrdenTrabajo $orden): ?string
    {
        $ruta = $this->rutaSiExiste($orden);

        return $ruta === null ? null : Storage::disk('local')->get($ruta);
    }

    private function rutaSiExiste(OrdenTrabajo $orden): ?string
    {
        $ruta = $orden->imagen_ruta;

        return (filled($ruta) && Storage::disk('local')->exists($ruta)) ? $ruta : null;
    }

    private function borrarArchivo(?string $ruta): void
    {
        if (filled($ruta)) {
            Storage::disk('local')->delete($ruta);
        }
    }
}
