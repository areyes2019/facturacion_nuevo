<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

/**
 * Base común de los correos que llevan el PDF de un documento adjunto (ver 012-ordenes-compra.md,
 * adición técnica 34). Cada documento conserva su propio mailable —y por lo tanto su asunto, su
 * plantilla y su nombre de clase— pero el adjunto se arma en un solo lugar.
 */
abstract class DocumentoEnviadoMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(protected readonly string $pdf) {}

    abstract protected function nombreArchivoPdf(): string;

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdf, $this->nombreArchivoPdf())
                ->withMime('application/pdf'),
        ];
    }
}
