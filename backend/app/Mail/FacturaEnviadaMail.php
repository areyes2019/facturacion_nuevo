<?php

namespace App\Mail;

use App\Models\Factura;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FacturaEnviadaMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Factura $factura,
        private readonly string $pdf,
        private readonly string $xml,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Factura '.($this->factura->facturapi_serie).($this->factura->facturapi_folio ?? $this->factura->folio),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.factura-enviada');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $folio = $this->factura->facturapi_folio ?? $this->factura->folio;

        return [
            Attachment::fromData(fn () => $this->pdf, "factura-{$folio}.pdf")
                ->withMime('application/pdf'),
            Attachment::fromData(fn () => $this->xml, "factura-{$folio}.xml")
                ->withMime('application/xml'),
        ];
    }
}
