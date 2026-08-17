<?php

namespace App\Contracts;

/**
 * Documento que además se manda por WhatsApp **desde el servidor**, vía Twilio.
 *
 * Es lo que 008 puso originalmente en `DocumentoEnviable` para la cotización. Se separó al pasar el
 * WhatsApp de la cotización al menú de compartir del propio aparato (ver 029-pwa-mostrador.md):
 * hoy solo las órdenes de compra de 012 salen por Twilio, y son las únicas que necesitan una URL
 * pública del PDF.
 */
interface DocumentoEnviablePorWhatsApp extends DocumentoEnviable
{
    /**
     * Texto del mensaje de WhatsApp que acompaña al PDF.
     */
    public function resumenWhatsApp(): string;

    /**
     * URL pública, firmada y temporal desde la que Twilio descarga el PDF adjunto: su
     * infraestructura no manda cookies de sesión y el PDF no se persiste en disco.
     */
    public function urlPdfPublico(): string;
}
