<?php

namespace App\Services\ConstanciaFiscal;

use chillerlan\QRCode\QRCode;
use GdImage;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;
use Throwable;

/**
 * Lectura del código QR de una Constancia de Situación Fiscal (ver
 * 016-constancia-situacion-fiscal-qr.md).
 *
 * El navegador lo intenta primero con el lector nativo del dispositivo (`BarcodeDetector`), que es
 * mucho mejor con fotos inclinadas o con sombra. Esto corre cuando el navegador no tiene ese lector
 * —Firefox y Safari todavía no— o no lo logró.
 *
 * Trabaja siempre sobre el contenido en memoria: nada se escribe en disco.
 */
class QrLector
{
    /** Por debajo de esto no cabe un QR legible; sirve para no decodificar viñetas ni adornos. */
    private const LADO_MINIMO = 50;

    /**
     * Tope de seguridad: una imagen enorme se convertiría punto por punto y solo para descartarla.
     * El QR de una constancia mide 150 × 150.
     */
    private const LADO_MAXIMO = 1200;

    /**
     * Dirección codificada en el QR de una imagen, o `null` si no se encontró ninguno.
     *
     * No propaga excepciones: "esta imagen no trae un QR legible" es un resultado previsto del
     * flujo, no una falla del sistema.
     */
    public function leer(string $imagen): ?string
    {
        try {
            $resultado = (new QRCode)->readFromBlob($imagen);
        } catch (Throwable) {
            return null;
        }

        $texto = trim((string) $resultado);

        return $texto === '' ? null : $texto;
    }

    /**
     * Dirección codificada en el QR que la constancia lleva **dentro del PDF**.
     *
     * El código no está dibujado con líneas: viaja como una imagen guardada en el archivo, así que
     * se puede sacar tal cual está, sin convertir la página a foto y sin perder un punto. Es lo que
     * permite que el servidor no dependa del lector de códigos del navegador ni de tener
     * ImageMagick o Ghostscript instalados.
     *
     * Una constancia trae **más de un QR**: el de la primera página apunta al validador del SAT y
     * el de la última codifica la cadena original del sello digital, que no identifica a nadie. Se
     * distinguen por su contenido y no por su posición, así que aquí solo vale el que trae idCIF y
     * RFC; quedarse con "el primero que se pueda leer" tomaría el equivocado en cuanto el orden de
     * las imágenes cambie.
     */
    public function leerDePdf(string $pdf): ?string
    {
        try {
            $objetos = (new Parser)->parseContent($pdf)->getObjects();
        } catch (Throwable) {
            return null;
        }

        foreach ($objetos as $objeto) {
            $imagen = $this->imagenCuadrada($objeto);

            if ($imagen === null) {
                continue;
            }

            $url = $this->leer($imagen);

            if ($url !== null && IdentidadQr::desdeUrl($url) !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * La imagen del objeto en un formato que el lector entienda, o `null` si no es una imagen
     * cuadrada de tamaño razonable. Un QR siempre lo es, y así los logotipos y las firmas del
     * documento se descartan sin gastar memoria en decodificarlos.
     */
    private function imagenCuadrada(PDFObject $objeto): ?string
    {
        try {
            $detalles = $objeto->getHeader()?->getDetails() ?? [];

            if (($detalles['Subtype'] ?? null) !== 'Image') {
                return null;
            }

            $lado = (int) ($detalles['Width'] ?? 0);

            if ($lado !== (int) ($detalles['Height'] ?? 0) || $lado < self::LADO_MINIMO || $lado > self::LADO_MAXIMO) {
                return null;
            }

            $filtros = (array) ($detalles['Filter'] ?? []);

            // Un JPEG dentro del PDF ya es un archivo de imagen completo: se pasa tal cual.
            if (in_array('DCTDecode', $filtros, true)) {
                return $objeto->getContent();
            }

            return $this->aPng($objeto->getContent(), $lado, $detalles);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Los demás filtros dejan los puntos en crudo, sin cabecera que diga cómo leerlos, así que hay
     * que envolverlos en un PNG. Solo se atienden las dos formas en que una constancia guarda su
     * QR: un byte por color y un byte por tono de gris.
     *
     * @param  array<string, mixed>  $detalles
     */
    private function aPng(string $puntos, int $lado, array $detalles): ?string
    {
        $porPunto = match ((string) ($detalles['ColorSpace'] ?? '')) {
            'DeviceRGB' => 3,
            'DeviceGray' => 1,
            default => 0,
        };

        if ($porPunto === 0) {
            return null;
        }

        $puntos = $this->sinPrediccion($puntos, $lado * $porPunto, $porPunto, $detalles);

        if (strlen($puntos) < $lado * $lado * $porPunto) {
            return null;
        }

        $imagen = imagecreatetruecolor($lado, $lado);

        if (! $imagen instanceof GdImage) {
            return null;
        }

        $posicion = 0;

        for ($y = 0; $y < $lado; $y++) {
            for ($x = 0; $x < $lado; $x++) {
                $rojo = ord($puntos[$posicion]);
                $verde = $porPunto === 3 ? ord($puntos[$posicion + 1]) : $rojo;
                $azul = $porPunto === 3 ? ord($puntos[$posicion + 2]) : $rojo;
                $posicion += $porPunto;

                imagesetpixel($imagen, $x, $y, ($rojo << 16) | ($verde << 8) | $azul);
            }
        }

        ob_start();
        imagepng($imagen);
        $png = (string) ob_get_clean();
        imagedestroy($imagen);

        return $png === '' ? null : $png;
    }

    /**
     * Deshace la predicción con la que muchos generadores de PDF comprimen sus imágenes: en vez de
     * guardar cada punto, guardan su diferencia con el vecino, que ocupa menos. Cada renglón queda
     * precedido por un byte que dice con cuál vecino se comparó.
     *
     * El SAT no la usa —sus constancias traen los puntos tal cual— pero cualquier PDF reimpreso o
     * vuelto a guardar con otra herramienta sí, y ese es un archivo que el usuario puede subir
     * perfectamente.
     *
     * @param  array<string, mixed>  $detalles
     */
    private function sinPrediccion(string $puntos, int $anchoFila, int $porPunto, array $detalles): string
    {
        $parametros = (array) ($detalles['DecodeParms'] ?? []);

        if ((int) ($parametros['Predictor'] ?? 1) < 10) {
            return $puntos;
        }

        $reconstruido = '';
        $anterior = str_repeat("\0", $anchoFila);
        $posicion = 0;
        $total = strlen($puntos);

        while ($posicion + 1 + $anchoFila <= $total) {
            $tipo = ord($puntos[$posicion]);
            $fila = substr($puntos, $posicion + 1, $anchoFila);
            $posicion += 1 + $anchoFila;
            $actual = '';

            for ($i = 0; $i < $anchoFila; $i++) {
                $izquierda = $i >= $porPunto ? ord($actual[$i - $porPunto]) : 0;
                $arriba = ord($anterior[$i]);
                $diagonal = $i >= $porPunto ? ord($anterior[$i - $porPunto]) : 0;

                $actual .= chr(match ($tipo) {
                    1 => ord($fila[$i]) + $izquierda,
                    2 => ord($fila[$i]) + $arriba,
                    3 => ord($fila[$i]) + intdiv($izquierda + $arriba, 2),
                    4 => ord($fila[$i]) + $this->vecinoMasProbable($izquierda, $arriba, $diagonal),
                    default => ord($fila[$i]),
                } & 0xFF);
            }

            $reconstruido .= $actual;
            $anterior = $actual;
        }

        return $reconstruido;
    }

    /**
     * El predictor "Paeth": de los tres vecinos, el que menos se aleja de su suma menos la
     * diagonal. Es el que mejor adivina en imágenes con bordes marcados, como un código QR.
     */
    private function vecinoMasProbable(int $izquierda, int $arriba, int $diagonal): int
    {
        $estimado = $izquierda + $arriba - $diagonal;

        $aIzquierda = abs($estimado - $izquierda);
        $aArriba = abs($estimado - $arriba);
        $aDiagonal = abs($estimado - $diagonal);

        if ($aIzquierda <= $aArriba && $aIzquierda <= $aDiagonal) {
            return $izquierda;
        }

        return $aArriba <= $aDiagonal ? $arriba : $diagonal;
    }
}
