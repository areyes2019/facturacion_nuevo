<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envío de WhatsApp vía la API REST de Twilio, usado para notificar cotizaciones (ver
 * 008-cotizaciones.md, adición técnica 28). Se implementa con una llamada HTTP directa
 * (`Illuminate\Support\Facades\Http`) en vez de instalar el SDK oficial de Twilio: el envío de
 * un mensaje de WhatsApp con un adjunto es una sola petición POST sencilla
 * (`Accounts/{Sid}/Messages.json`), así que no se justifica la dependencia completa del SDK.
 *
 * Twilio requiere que `MediaUrl` sea una URL **pública** que su infraestructura pueda descargar;
 * como el PDF de la cotización se genera al vuelo sin persistirse, quien llama a este servicio
 * debe pasar la URL firmada temporal (`cotizaciones.pdf-publico`, fuera de `auth:sanctum`) en vez
 * de una ruta protegida.
 */
class TwilioWhatsAppService
{
    public function enviar(string $telefonoDestino, string $mensaje, string $urlPdf): void
    {
        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');
        $numeroOrigen = config('services.twilio.whatsapp_from');

        if (! $accountSid || ! $authToken || ! $numeroOrigen) {
            throw new RuntimeException('Credenciales de Twilio no configuradas (TWILIO_ACCOUNT_SID/TWILIO_AUTH_TOKEN/TWILIO_WHATSAPP_FROM).');
        }

        $respuesta = Http::asForm()
            ->withBasicAuth((string) $accountSid, (string) $authToken)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                'To' => 'whatsapp:'.$this->normalizarTelefono($telefonoDestino),
                'From' => 'whatsapp:'.$numeroOrigen,
                'Body' => $mensaje,
                'MediaUrl' => $urlPdf,
            ]);

        if ($respuesta->failed()) {
            throw new RuntimeException('No se pudo enviar el mensaje de WhatsApp: '.$respuesta->body());
        }
    }

    private function normalizarTelefono(string $telefono): string
    {
        return '+'.(preg_replace('/\D/', '', $telefono) ?? '');
    }
}
