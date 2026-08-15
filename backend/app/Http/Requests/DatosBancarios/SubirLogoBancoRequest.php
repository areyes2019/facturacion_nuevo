<?php

namespace App\Http\Requests\DatosBancarios;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Logo de un banco (ver 026-datos-bancarios-cotizacion.md).
 *
 * `image` aquí es solo el primer filtro; la comprobación que vale es la que hace
 * `ProcesadorImagen` leyendo los bytes del archivo. El tope de 2 MB es de entrada: lo que se guarda
 * es un icono de 64 puntos que pesa unos pocos kilobytes.
 */
class SubirLogoBancoRequest extends FormRequest
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
            'archivo' => ['required', 'file', 'image', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.max' => 'El logo no debe pesar más de 2 MB.',
        ];
    }
}
