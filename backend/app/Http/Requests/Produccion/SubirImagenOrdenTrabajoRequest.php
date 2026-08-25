<?php

namespace App\Http\Requests\Produccion;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Mismas reglas que `SubirImagenArticuloRequest` (020): la imagen se reduce a 1200 px antes de guardarse. */
class SubirImagenOrdenTrabajoRequest extends FormRequest
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
            'archivo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ];
    }
}
