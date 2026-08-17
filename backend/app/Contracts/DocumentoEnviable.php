<?php

namespace App\Contracts;

use Illuminate\Mail\Mailable;

/**
 * Documento que se le puede mandar a alguien por correo con su PDF adjunto.
 *
 * 008 escribió ese mecanismo acoplado a `Cotizacion`; 012 lo necesita idéntico para
 * `OrdenCompra`, así que se generaliza aquí en vez de duplicarlo (ver 012-ordenes-compra.md,
 * adición técnica 34). Quien implementa esta interfaz aporta lo único que cambia entre documentos:
 * qué plantilla renderiza, cómo se llama el archivo y qué mailable lo lleva.
 *
 * Lo que hace falta para mandarlo por WhatsApp desde el servidor vive aparte, en
 * `DocumentoEnviablePorWhatsApp`: la cotización dejó de necesitarlo cuando su WhatsApp pasó a
 * compartirse desde el aparato del usuario (ver 029-pwa-mostrador.md).
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
}
