<?php

namespace App\Services;

use App\Models\Articulo;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Puerta única por la que entra cualquier imagen de artículo (ver 020-imagenes-articulos.md).
 *
 * La subida individual y la carga masiva pasan por aquí, de modo que una foto guardada es siempre
 * la misma clase de archivo sin importar por dónde llegó: comprobada por contenido, reducida a
 * 1200 puntos de lado largo y reescrita como WEBP con un nombre que genera el sistema.
 */
class ImagenArticuloService
{
    /** Lado largo máximo, en puntos. Una foto de celular ronda los 4000 y no aporta nada de más. */
    public const LADO_MAXIMO = 1200;

    /** Calidad de la recompresión WEBP. */
    private const CALIDAD = 82;

    /**
     * Formatos que se aceptan al subir. La salida es siempre WEBP, independientemente de con cuál
     * de estos haya entrado la imagen.
     *
     * @var array<int, int>
     */
    private const TIPOS_ACEPTADOS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Guarda el archivo como imagen del artículo y borra la que tuviera antes.
     *
     * Devuelve la ruta relativa dentro del disco privado. Lanza `RuntimeException` con un motivo
     * legible si el archivo no es una imagen de un formato aceptado: quien llama decide si eso es
     * un `422` (subida individual) o un renglón del reporte (carga masiva).
     */
    public function guardar(Articulo $articulo, string $rutaOrigen): string
    {
        $contenido = $this->procesar($rutaOrigen);

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
        $ruta = $articulo->imagen_ruta;

        if (! filled($ruta) || ! Storage::disk('local')->exists($ruta)) {
            return null;
        }

        return Storage::disk('local')->get($ruta);
    }

    /**
     * Lee el archivo, lo reduce si hace falta y devuelve su contenido ya en WEBP.
     *
     * Los recursos de GD se liberan pase lo que pase: una carga masiva procesa decenas de imágenes
     * en la misma petición, y una fuga por cada archivo rechazado agota la memoria antes del final.
     */
    private function procesar(string $rutaOrigen): string
    {
        $original = $this->abrir($rutaOrigen);

        try {
            $reducida = $this->reducir($original);
        } catch (Throwable $e) {
            imagedestroy($original);

            throw $e;
        }

        if ($reducida !== $original) {
            imagedestroy($original);
        }

        try {
            return $this->codificar($reducida);
        } finally {
            imagedestroy($reducida);
        }
    }

    /**
     * Abre el archivo como imagen tras comprobar **por contenido** que lo es.
     *
     * La terminación del nombre no se mira en ningún momento: un archivo llamado `producto.jpg`
     * puede ser cualquier cosa, y guardarlo como si fuera una foto deja un cuadro roto en la ficha
     * sin ninguna explicación.
     */
    private function abrir(string $rutaOrigen): GdImage
    {
        $info = @getimagesize($rutaOrigen);

        if ($info === false || ! in_array($info[2], self::TIPOS_ACEPTADOS, true)) {
            throw new RuntimeException('no es una imagen JPG, PNG ni WEBP');
        }

        $imagen = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($rutaOrigen),
            IMAGETYPE_PNG => @imagecreatefrompng($rutaOrigen),
            IMAGETYPE_WEBP => @imagecreatefromwebp($rutaOrigen),
            default => false,
        };

        if ($imagen === false) {
            throw new RuntimeException('la imagen está dañada y no se pudo leer');
        }

        return $imagen;
    }

    /**
     * Devuelve la imagen con su lado largo en 1200 puntos como máximo, conservando la proporción.
     *
     * Una imagen que ya cabe se devuelve tal cual, sin copiarse: **nunca se amplía**, porque agrandar
     * no agrega detalle y sí peso.
     *
     * El redimensionado se hace a mano en vez de con `imagescale` para poder apagar la mezcla de
     * capas y conservar el canal de transparencia: sin eso, un recorte con fondo transparente sale
     * con el fondo negro, que es peor que perder la transparencia sin más.
     */
    private function reducir(GdImage $imagen): GdImage
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $lado = max($ancho, $alto);

        if ($lado <= self::LADO_MAXIMO) {
            return $imagen;
        }

        $escala = self::LADO_MAXIMO / $lado;
        $anchoDestino = max(1, (int) round($ancho * $escala));
        $altoDestino = max(1, (int) round($alto * $escala));

        $destino = imagecreatetruecolor($anchoDestino, $altoDestino);

        if ($destino === false) {
            throw new RuntimeException('no se pudo redimensionar la imagen');
        }

        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));

        imagecopyresampled($destino, $imagen, 0, 0, 0, 0, $anchoDestino, $altoDestino, $ancho, $alto);

        return $destino;
    }

    /**
     * Codifica la imagen como WEBP.
     *
     * WEBP y no JPEG porque la mayor parte del material ya viene en WEBP: convertirlo lo haría
     * entre 25% y 35% más pesado, lo pasaría por una segunda compresión con pérdida y le quitaría
     * la transparencia a los recortes. Por eso se preserva el canal alfa antes de codificar.
     */
    private function codificar(GdImage $imagen): string
    {
        imagepalettetotruecolor($imagen);
        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);

        ob_start();
        $ok = imagewebp($imagen, null, self::CALIDAD);
        $contenido = (string) ob_get_clean();

        if (! $ok || $contenido === '') {
            throw new RuntimeException('no se pudo generar la imagen WEBP');
        }

        return $contenido;
    }

    private function borrarArchivo(?string $ruta): void
    {
        if (filled($ruta)) {
            Storage::disk('local')->delete($ruta);
        }
    }
}
