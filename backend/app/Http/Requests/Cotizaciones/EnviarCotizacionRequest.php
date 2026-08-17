<?php

namespace App\Http\Requests\Cotizaciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EnviarCotizacionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // El canal `whatsapp` se retiró: ese envío ya no sale del servidor, lo comparte el
            // aparato del usuario con el PDF que descarga (ver 029-pwa-mostrador.md). `canal` se
            // conserva —en vez de quitarlo— porque el correo sigue siendo un canal entre varios
            // posibles y el frontend ya lo manda.
            'canal' => ['required', 'in:correo'],
            'destinatarios' => ['required', 'array', 'min:1'],
            'destinatarios.*' => ['required', 'email'],
        ];
    }
}
