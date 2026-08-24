<?php

namespace App\Http\Controllers;

use App\Http\Requests\Landing\StoreContactoLandingRequest;
use App\Mail\ContactoLandingMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

/**
 * Recibe el formulario de contacto de la landing pública (prosello.com.mx), fuera del sistema de
 * facturación: sin sesión, sin guardar nada en base de datos (ver 037-landing-prosello.md).
 */
class ContactoLandingController extends Controller
{
    public function store(StoreContactoLandingRequest $request): JsonResponse
    {
        // Honeypot lleno: responde éxito sin enviar correo, para no delatar el filtro a quien lo
        // automatizó.
        if (filled($request->input('empresa_web'))) {
            return response()->json(['mensaje' => 'Gracias, te contactaremos pronto.']);
        }

        Mail::to(config('services.landing.contacto_email'))->send(new ContactoLandingMail(
            nombre: $request->string('nombre')->trim()->toString(),
            correo: $request->string('correo')->trim()->toString(),
            telefono: $request->string('telefono')->trim()->toString(),
            mensaje: $request->string('mensaje')->trim()->toString(),
        ));

        return response()->json(['mensaje' => 'Gracias, te contactaremos pronto.']);
    }
}
