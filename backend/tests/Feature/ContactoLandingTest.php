<?php

use App\Mail\ContactoLandingMail;
use Illuminate\Support\Facades\Mail;

/**
 * Formulario de contacto de la landing pública (ver 037-landing-prosello.md). Sin sesión, sin
 * registro en base de datos: el correo es el único efecto observable.
 */
test('un contacto valido envia el correo al buzon configurado', function () {
    Mail::fake();

    $response = $this->postJson('/api/v1/contacto', [
        'nombre' => 'María Pérez',
        'correo' => 'maria@ejemplo.com',
        'telefono' => '4611234567',
        'mensaje' => 'Quiero información sobre mecanismos para sellos.',
    ]);

    $response->assertOk();

    Mail::assertSent(ContactoLandingMail::class, function (ContactoLandingMail $mail) {
        return $mail->nombre === 'María Pérez'
            && $mail->correo === 'maria@ejemplo.com'
            && $mail->hasTo(config('services.landing.contacto_email'));
    });
});

test('el honeypot lleno responde exito sin enviar correo', function () {
    Mail::fake();

    $response = $this->postJson('/api/v1/contacto', [
        'nombre' => 'Bot',
        'correo' => 'bot@ejemplo.com',
        'telefono' => '0000000000',
        'mensaje' => 'spam',
        'empresa_web' => 'http://spam.example',
    ]);

    $response->assertOk();

    Mail::assertNothingSent();
});

test('faltan campos requeridos responde 422', function () {
    $response = $this->postJson('/api/v1/contacto', [
        'correo' => 'no-es-un-correo',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['nombre', 'correo', 'telefono', 'mensaje']);
});

test('mas de diez envios por minuto desde la misma ip reciben 429', function () {
    Mail::fake();

    $payload = [
        'nombre' => 'María Pérez',
        'correo' => 'maria@ejemplo.com',
        'telefono' => '4611234567',
        'mensaje' => 'Quiero información sobre mecanismos para sellos.',
    ];

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/contacto', $payload)->assertOk();
    }

    $this->postJson('/api/v1/contacto', $payload)->assertStatus(429);
});
