<?php

namespace App\Services;

use App\Models\Articulo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puerta única por la que entra cualquier imagen de artículo (ver 020-imagenes-articulos.md).
 *
 * La subida individual y la carga masiva pasan por aquí, de modo que una foto guardada es siempre
 * la misma clase de archivo sin importar por dónde llegó: comprobada por contenido, reducida a
 * 1200 puntos de lado largo y reescrita como WEBP con un nombre que genera el sistema.
 *
 * El redimensionado en sí vive en `ProcesadorImagen` desde que los logos de banco necesitaron lo
 * mismo con otro tamaño (ver 026-datos-bancarios-cotizacion.md). Aquí se queda lo propio del
 * artículo: dónde va el archivo, cómo se llama y qué pasa con el anterior.
 */
class ImagenArticuloService
{
    /** Lado largo máximo, en puntos. Una foto de celular ronda los 4000 y no aporta nada de más. */
    public const LADO_MAXIMO = 1200;

    public function __construct(private readonly ProcesadorImagen $procesador) {}

    /**
     * Guarda el archivo como imagen del artículo y borra la que tuviera antes.
     *
     * Devuelve la ruta relativa dentro del disco privado. Lanza `RuntimeException` con un motivo
     * legible si el archivo no es una imagen de un formato aceptado: quien llama decide si eso es
     * un `422` (subida individual) o un renglón del reporte (carga masiva).
     */
    public function guardar(Articulo $articulo, string $rutaOrigen): string
    {
        $contenido = $this->procesador->procesar($rutaOrigen, self::LADO_MAXIMO);

        $anterior = $articulo->imagen_ruta;
        $ruta = Articulo::DIRECTORIO_IMAGENES.'/'.$articulo->id.'-'.Str::lower(Str::random(8)).'.webp';

        Storage::disk('local')->put($ruta, $contenido);

        $articulo->forceFill(['imagen_ruta' => $ruta])->save();

        // Se borra después de guardar la nueva y no antes: si el guardado falla, el artículo se
        // queda con la imagen que ya tenía en vez de perder las dos.
        $this->borrarArchivo($anterior);

        return $ruta;
    }

    /**
     * Quita la imagen del artículo y borra su archivo. No falla si el artículo no tenía ninguna.
     */
    public function eliminar(Articulo $articulo): void
    {
        $anterior = $articulo->imagen_ruta;

        $articulo->forceFill(['imagen_ruta' => null])->save();

        $this->borrarArchivo($anterior);
    }

    /**
     * Contenido binario de la imagen, o `null` si no hay o el archivo ya no está en disco.
     *
     * Mismo criterio que `Emisor::contenidoLogo` en 019: la ausencia del archivo se distingue de
     * "no hay imagen" y quien llama decide qué hacer con el `null`.
     */
    public function contenido(Articulo $articulo): ?string
    {
        $ruta = $this->rutaSiExiste($articulo);

        return $ruta === null ? null : Storage::disk('local')->get($ruta);
    }

    /**
     * Miniatura de la imagen del artículo, ya reducida y codificada como data URI, lista para
     * incrustarse en un PDF (ver 028-lista-precios-pdf.md).
     *
     * Es un segundo tamaño, no la foto de `contenido()`: mismo criterio que `LogoBancoService`
     * (ver 026-datos-bancarios-cotizacion.md), que reutiliza el mismo `ProcesadorImagen` con otro
     * número. La foto guardada ya está reducida a `LADO_MAXIMO` (1200), pero eso sigue siendo
     * demasiado pesado para una miniatura de unos 15 mm en una tabla que puede llevar decenas de
     * artículos: incrustarla entera multiplicaría el peso y el tiempo de generación del PDF por
     * cada fila.
     *
     * Devuelve `null` sin registrar nada si no hay imagen, si el archivo ya no está en disco o si
     * está dañado: a diferencia del logo del emisor, la miniatura de un artículo es decorativa, no
     * algo que alguien vaya a extrañar en un log.
     */
    public function miniaturaBase64(Articulo $articulo, int $ladoMaximo): ?string
    {
        $ruta = $this->rutaSiExiste($articulo);

        if ($ruta === null) {
            return null;
        }

        try {
            $contenido = $this->procesador->procesar(Storage::disk('local')->path($ruta), $ladoMaximo);
        } catch (Throwable) {
            return null;
        }

        return 'data:image/webp;base64,'.base64_encode($contenido);
    }

    /**
     * La ruta guardada, solo si el archivo todavía existe en disco.
     */
    private function rutaSiExiste(Articulo $articulo): ?string
    {
        $ruta = $articulo->imagen_ruta;

        return (filled($ruta) && Storage::disk('local')->exists($ruta)) ? $ruta : null;
    }

    private function borrarArchivo(?string $ruta): void
    {
        if (filled($ruta)) {
            Storage::disk('local')->delete($ruta);
        }
    }
}
