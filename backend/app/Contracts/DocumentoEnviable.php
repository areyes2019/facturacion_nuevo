<?php

namespace App\Contracts;

use Illuminate\Mail\Mailable;

/**
 * Documento que se le puede mandar a alguien por correo o WhatsApp con su PDF.
 *
 * 008 escribió ese mecanismo acoplado a `Cotizacion`; 012 lo necesita idéntico para
 * `OrdenCompra`, así que se generaliza aquí en vez de duplicarlo (ver 012-ordenes-compra.md,
 * adición técnica 34). Quien implementa esta interfaz aporta lo único que cambia entre documentos:
 * qué plantilla renderiza, cómo se llama el archivo, qué mailable lo lleva, qué dice el WhatsApp y
 * de qué ruta firmada sale su PDF público.
 */
interface DocumentoEnviable
{
    /**
     * Vista Blade que renderiza el PDF.
     */
    public function vistaPdf(): string;

    /**
     * Datos que recibe esa vista. La implementación se encarga de cargar las relaciones que
     * necesite antes de devolverlos.
     *
     * @return array<string, mixed>
     */
    public function datosPdf(): array;

    public function nombreArchivoPdf(): string;

    /**
     * Mailable propio del documento, con el PDF ya generado listo para adjuntarse.
     */
    public function mailable(string $pdf): Mailable;

    /**
     * Texto del mensaje de WhatsApp que acompaña al PDF.
     */
    public function resumenWhatsApp(): string;

    /**
     * URL pública, firmada y temporal desde la que Twilio descarga el PDF adjunto: su
     * infraestructura no manda cookies de sesión y el PDF no se persiste en disco (ver
     * 008-cotizaciones.md, adición técnica 28).
     */
    public function urlPdfPublico(): string;
}
