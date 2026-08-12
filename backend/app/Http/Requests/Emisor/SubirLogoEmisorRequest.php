<?php

namespace App\Http\Requests\Emisor;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubirLogoEmisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Sin SVG: dompdf no lo dibuja de forma confiable y el usuario se quedaría mirando un
            // hueco sin saber por qué. El tope de 2 MB es por PDF generado: el logo se incrusta
            // entero en cada documento y en cada correo que lo lleva adjunto.
            'archivo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'tipo' => ['required', 'string', 'in:principal,marca'],
        ];
    }
}
