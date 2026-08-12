<?php

namespace App\Mail;

use App\Models\OrdenCompra;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrdenCompraEnviadaMail extends DocumentoEnviadoMail
{
    public function __construct(
        public readonly OrdenCompra $ordenCompra,
        string $pdf,
    ) {
        parent::__construct($pdf);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Orden de compra '.$this->ordenCompra->folioFormateado(),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orden-compra-enviada');
    }

    protected function nombreArchivoPdf(): string
    {
        return $this->ordenCompra->nombreArchivoPdf();
    }
}
