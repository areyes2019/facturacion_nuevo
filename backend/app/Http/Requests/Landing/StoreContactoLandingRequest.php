<?php

namespace App\Http\Requests\Landing;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactoLandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'correo' => ['required', 'string', 'email', 'max:255'],
            'telefono' => ['required', 'string', 'max:20'],
            'mensaje' => ['required', 'string', 'max:2000'],
            // Honeypot (ver 037-landing-prosello.md): no se valida aquí a propósito. Si fallara
            // como regla normal, un bot vería el 422 y sabría que lo detectamos; el controlador lo
            // revisa después de pasar la validación y responde éxito sin enviar el correo.
            'empresa_web' => ['nullable', 'string'],
        ];
    }
}
