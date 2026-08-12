<?php

namespace App\Http\Requests\Articulos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubirImagenArticuloRequest extends FormRequest
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
            // El tope de 10 MB es sobre lo que sube el usuario, no sobre lo que se guarda: la
            // imagen se reduce a 1200 puntos antes de almacenarse. Una fotografía de producto que
            // pese más que eso no es una fotografía de producto (ver 020-imagenes-articulos.md).
            'archivo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ];
    }
}
