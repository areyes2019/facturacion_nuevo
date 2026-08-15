<?php

namespace App\Services;

use GdImage;
use RuntimeException;
use Throwable;

/**
 * Convierte cualquier archivo subido en una imagen WEBP de un tamaño máximo dado.
 *
 * Nació dentro de `ImagenArticuloService` (ver 020-imagenes-articulos.md) y se extrajo al agregar
 * los logos de banco (ver 026-datos-bancarios-cotizacion.md), que necesitan exactamente lo mismo
 * con otro número: 64 puntos en vez de 1200. Las trampas de GD que hay aquí —el canal alfa, la
 * liberación de recursos, la comprobación por contenido— costaron descubrirse, y una copia se
 * habría quedado atrás la primera vez que se corrigiera un caso en el original.
 *
 * No sabe nada de modelos ni de discos: recibe una ruta, devuelve bytes.
 */
class ProcesadorImagen
{
    /** Calidad de la recompresión WEBP. */
    private const CALIDAD = 82;

    /**
     * Formatos que se aceptan a la entrada. La salida es siempre WEBP, entre con el que entre.
     *
     * @var array<int, int>
     */
    private const TIPOS_ACEPTADOS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Lee el archivo, lo reduce si hace falta y devuelve su contenido ya en WEBP.
     *
     * Lanza `RuntimeException` con un motivo legible si el archivo no es una imagen de un formato
     * aceptado: quien llama decide si eso es un `422` o un renglón de un reporte.
     *
     * Los recursos de GD se liberan pase lo que pase: una carga masiva procesa decenas de imágenes
     * en la misma petición, y una fuga por cada archivo rechazado agota la memoria antes del final.
     */
    public function procesar(string $rutaOrigen, int $ladoMaximo): string
    {
        $original = $this->abrir($rutaOrigen);

        try {
            $reducida = $this->reducir($original, $ladoMaximo);
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
     * Devuelve la imagen con su lado largo en `$ladoMaximo` puntos como máximo, conservando la
     * proporción.
     *
     * Una imagen que ya cabe se devuelve tal cual, sin copiarse: **nunca se amplía**, porque
     * agrandar no agrega detalle y sí peso.
     *
     * El redimensionado se hace a mano en vez de con `imagescale` para poder apagar la mezcla de
     * capas y conservar el canal de transparencia: sin eso, un recorte con fondo transparente sale
     * con el fondo negro, que es peor que perder la transparencia sin más.
     */
    private function reducir(GdImage $imagen, int $ladoMaximo): GdImage
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $lado = max($ancho, $alto);

        if ($lado <= $ladoMaximo) {
            return $imagen;
        }

        $escala = $ladoMaximo / $lado;
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
}
