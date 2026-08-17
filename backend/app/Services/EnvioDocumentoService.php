<?php

namespace App\Services;

use App\Contracts\DocumentoEnviable;
use App\Contracts\DocumentoEnviablePorWhatsApp;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

/**
 * Punto único de generación y envío del PDF de un documento (ver 012-ordenes-compra.md, adición
 * técnica 34). Lo usan tanto Cotizaciones (008) como Órdenes de compra (012).
 *
 * El PDF siempre se genera al vuelo y nunca se persiste, mismo criterio que 007/008.
 */
class EnvioDocumentoService
{
    public function __construct(private readonly TwilioWhatsAppService $whatsapp) {}

    public function generarPdf(DocumentoEnviable $documento): string
    {
        return app('dompdf.wrapper')
            ->loadView($documento->vistaPdf(), $documento->datosPdf())
            ->output();
    }

    public function streamPdf(DocumentoEnviable $documento): Response
    {
        return app('dompdf.wrapper')
            ->loadView($documento->vistaPdf(), $documento->datosPdf())
            ->stream($documento->nombreArchivoPdf());
    }

    /**
     * @param  array<int, string>  $destinatarios
     */
    public function enviarPorCorreo(DocumentoEnviable $documento, array $destinatarios): void
    {
        Mail::to($destinatarios)->send($documento->mailable($this->generarPdf($documento)));
    }

    /**
     * Twilio exige una URL pública para el adjunto, así que se le pasa la firmada temporal del
     * documento en vez de una ruta protegida por sesión.
     *
     * Solo las órdenes de compra pasan por aquí: el WhatsApp de la cotización lo comparte el propio
     * aparato del usuario (ver 029-pwa-mostrador.md).
     */
    public function enviarPorWhatsApp(DocumentoEnviablePorWhatsApp $documento, string $telefono): void
    {
        $this->whatsapp->enviar($telefono, $documento->resumenWhatsApp(), $documento->urlPdfPublico());
    }
}
