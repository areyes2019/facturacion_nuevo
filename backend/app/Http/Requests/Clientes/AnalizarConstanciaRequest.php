<?php

namespace App\Http\Requests\Clientes;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AnalizarConstanciaRequest extends FormRequest
{
    /** Ver 016-constancia-situacion-fiscal-qr.md. */
    private const KILOBYTES_MAXIMOS = 10240;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Las tres partes son opcionales porque el frontend llama a este endpoint una o dos veces: la
     * primera con solo la dirección del QR (unos cientos de bytes, el caso común), y la segunda
     * —solo si el SAT no respondió— adjuntando los archivos para la Estrategia B.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'qr_url' => ['nullable', 'string', 'url', 'max:2048'],
            // El tipo se valida por el contenido real del archivo, no por su extensión: un .exe
            // renombrado a .png no pasa.
            'imagen' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:'.self::KILOBYTES_MAXIMOS],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:'.self::KILOBYTES_MAXIMOS],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('qr_url') || $this->hasFile('imagen') || $this->hasFile('pdf')) {
                    return;
                }

                $validator->errors()->add('qr_url', 'Envía la dirección del código QR, una imagen o un PDF de la constancia.');
            },
        ];
    }
}
