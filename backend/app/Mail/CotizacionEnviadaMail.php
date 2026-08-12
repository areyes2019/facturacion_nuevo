<?php

namespace App\Mail;

use App\Models\Cotizacion;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CotizacionEnviadaMail extends DocumentoEnviadoMail
{
    public function __construct(
        public readonly Cotizacion $cotizacion,
        string $pdf,
    ) {
        parent::__construct($pdf);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cotización '.$this->cotizacion->folio,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.cotizacion-enviada');
    }

    protected function nombreArchivoPdf(): string
    {
        return $this->cotizacion->nombreArchivoPdf();
    }
}
