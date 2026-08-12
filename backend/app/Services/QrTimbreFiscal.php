<?php

namespace App\Services;

use App\Models\Emisor;
use App\Models\Factura;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Código QR de verificación del Timbre Fiscal Digital (ver 019-formato-pdf-documentos.md).
 *
 * Se dibuja **en este servidor**, con la librería que ya estaba instalada para el lector de
 * constancias (016). El formato de referencia lo descargaba de un servicio ajeno en cada
 * impresión, con `@file_get_contents`: eso falla en silencio y deja facturas sin QR sin que nadie
 * se entere, le cuenta a un tercero el RFC del cliente y el importe de cada factura, depende de que
 * el servidor tenga salida a internet en ese instante, y descansa en un servicio gratuito que puede
 * limitarse o desaparecer.
 *
 * Los dos métodos están separados a propósito: la dirección es lo que se prueba y lo que se imprime
 * como texto cuando la imagen no se puede generar.
 */
class QrTimbreFiscal
{
    private const VERIFICADOR = 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx';

    /**
     * Dirección de verificación del SAT para una factura timbrada, o `null` si le falta alguno de
     * los cinco datos que la componen.
     *
     * El total va con 6 decimales y sin separador de miles, y `fe` son los **últimos 8 caracteres
     * del sello del CFDI**, ambos como los pide el Anexo 20.
     */
    public function url(Factura $factura, Emisor $emisor): ?string
    {
        $sello = (string) $factura->sello_cfdi;

        if (! filled($factura->uuid_fiscal) || ! filled($emisor->rfc) || strlen($sello) < 8) {
            return null;
        }

        return self::VERIFICADOR.'?'.http_build_query([
            'id' => $factura->uuid_fiscal,
            're' => $emisor->rfc,
            'rr' => $factura->cliente?->rfc ?? '',
            'tt' => number_format((float) $factura->total, 6, '.', ''),
            'fe' => substr($sello, -8),
        ]);
    }

    /**
     * Lo que el bloque del timbre necesita: la dirección de verificación y su imagen.
     *
     * Cualquiera de las dos puede venir en `null`. La plantilla imprime la imagen si la hay, y si
     * no, la dirección como texto, de modo que el comprobante siga siendo verificable a mano. El
     * PDF nunca se cae por el QR, pero la falla **siempre deja rastro** — que es justo lo contrario
     * del `@file_get_contents` del formato de referencia.
     *
     * @return array{url: string|null, imagen: string|null}
     */
    public function datos(Factura $factura, Emisor $emisor): array
    {
        $url = $this->url($factura, $emisor);

        if ($url === null) {
            Log::error('No se pudo armar la dirección de verificación del SAT para el QR.', [
                'factura' => $factura->folio,
                'motivo' => filled($emisor->rfc) ? 'faltan datos del timbrado' : 'falta el RFC del emisor',
            ]);

            return ['url' => null, 'imagen' => null];
        }

        $imagen = $this->imagenBase64($url);

        if ($imagen === null) {
            Log::error('No se pudo dibujar el QR del timbre fiscal.', ['factura' => $factura->folio]);
        }

        return ['url' => $url, 'imagen' => $imagen];
    }

    /**
     * El QR listo para incrustarse en el HTML del PDF, o `null` si no se pudo generar.
     */
    public function imagenBase64(string $url): ?string
    {
        try {
            $qr = new QRCode(new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'outputBase64' => true,
                'scale' => 4,
                'quietzoneSize' => 1,
            ]));

            return $qr->render($url);
        } catch (Throwable) {
            return null;
        }
    }
}
