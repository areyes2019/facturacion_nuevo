<?php

namespace App\Http\Controllers;

use App\Http\Requests\Clientes\AnalizarConstanciaRequest;
use App\Services\ConstanciaFiscal\CamposConstancia;
use App\Services\ConstanciaFiscal\ConstanciaPdfExtractor;
use App\Services\ConstanciaFiscal\IdentidadQr;
use App\Services\ConstanciaFiscal\QrLector;
use App\Services\ConstanciaFiscal\SatConsultaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * Lectura de la Constancia de Situación Fiscal para precargar el alta de un cliente (ver
 * 016-constancia-situacion-fiscal-qr.md).
 *
 * Nada se persiste aquí: ni el archivo, ni una bitácora, ni el cliente. Este endpoint solo
 * propone datos; el alta sigue ocurriendo con el `POST /api/v1/clientes` de 004.
 */
class ConstanciaFiscalController extends Controller
{
    public function __construct(
        private readonly QrLector $qrLector,
        private readonly SatConsultaService $sat,
        private readonly ConstanciaPdfExtractor $pdfExtractor,
    ) {}

    public function analizar(AnalizarConstanciaRequest $request): JsonResponse
    {
        $imagen = $request->file('imagen');
        $pdf = $request->file('pdf');
        $contenidoPdf = $pdf === null ? null : (string) file_get_contents($pdf->getRealPath());

        $qrUrl = $request->filled('qr_url')
            ? trim((string) $request->input('qr_url'))
            : $this->buscarQr($contenidoPdf, $imagen);

        $identidad = null;

        if ($qrUrl !== null) {
            if (! $this->sat->urlEsOficial($qrUrl)) {
                return response()->json(['error' => 'QR_NO_OFICIAL'], 422);
            }

            // Del propio QR salen el idCIF y el RFC, así que la identidad del contribuyente queda
            // fijada aquí: pase lo que pase con el SAT, el RFC ya no depende de leer el documento.
            $identidad = IdentidadQr::desdeUrl($qrUrl);

            if ($this->sat->disponible() && ($campos = $this->sat->consultar($qrUrl, $identidad)) !== null) {
                return $this->respuesta($request, $campos, 'SAT_QR_DIRECT', 'oficial');
            }

            // El SAT no está. Si la llamada vino sin archivos —el caso común, donde el frontend
            // solo mandó la dirección del QR— se le pide que repita adjuntándolos, y así la
            // Estrategia B tiene de dónde leer.
            if ($imagen === null && $contenidoPdf === null) {
                return response()->json(['error' => 'SAT_NO_DISPONIBLE', 'estrategia_b' => true], 503);
            }
        }

        if ($contenidoPdf !== null) {
            $campos = $this->pdfExtractor->extraer($contenidoPdf, $identidad);

            if ($campos !== null && $campos->traeDatosDelContribuyente()) {
                return $this->respuesta($request, $campos, 'PDF_TEXTO', 'documento');
            }

            return response()->json([
                'error' => 'PDF_SIN_TEXTO',
                'puede_ocr' => $imagen !== null,
            ], 422);
        }

        return response()->json([
            'error' => $qrUrl === null ? 'QR_NO_LEGIBLE' : 'SIN_DATOS',
            'puede_ocr' => $imagen !== null,
        ], 422);
    }

    /**
     * El QR se busca primero dentro del PDF, donde la constancia lo guarda como imagen y se lee sin
     * pérdida, y solo después en la foto que haya subido el navegador. El orden importa: leerlo del
     * PDF no depende de la calidad de una captura ni de que el navegador traiga lector de códigos.
     */
    private function buscarQr(?string $contenidoPdf, ?UploadedFile $imagen): ?string
    {
        if ($contenidoPdf !== null && ($url = $this->qrLector->leerDePdf($contenidoPdf)) !== null) {
            return $url;
        }

        if ($imagen === null) {
            return null;
        }

        return $this->qrLector->leer((string) file_get_contents($imagen->getRealPath()));
    }

    private function respuesta(
        AnalizarConstanciaRequest $request,
        CamposConstancia $campos,
        string $fuente,
        string $confianza,
    ): JsonResponse {
        // Solo se informa del duplicado; quien lo impide de verdad sigue siendo la regla `unique`
        // de 004 al guardar. Así el frontend puede ofrecer abrir la ficha existente en vez de
        // empujar al usuario contra un error de validación.
        $existente = $request->user()->clientes()
            ->where('rfc', $campos->rfc)
            ->first();

        return response()->json([
            'fuente' => $fuente,
            'confianza' => $confianza,
            'advertencias' => $campos->advertencias,
            'data' => $campos->toArray(),
            'cliente_existente' => $existente === null ? null : [
                'id' => $existente->id,
                'razon_social' => $existente->razon_social,
            ],
        ]);
    }
}
