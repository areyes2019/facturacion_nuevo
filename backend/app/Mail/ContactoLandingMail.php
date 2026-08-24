<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Correo del formulario de contacto de la landing de prosello.com.mx (ver 037-landing-prosello.md).
 * No hay registro en base de datos: este correo es el único rastro del contacto.
 */
class ContactoLandingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nombre,
        public readonly string $correo,
        public readonly string $telefono,
        public readonly string $mensaje,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Contacto desde prosello.com.mx: {$this->nombre}",
            replyTo: [$this->correo],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.contacto-landing');
    }
}
