<?php

namespace App\Http\Requests\Inventario;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Umbrales de reposición de un artículo (ver 017-inventario.md).
 *
 * No genera movimiento de inventario: cambiar un umbral no mueve piezas.
 */
class ActualizarParametrosInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `minimo` en 0 significa "no me avises de este artículo".
            'minimo' => ['required', 'integer', 'min:0'],
            // `maximo` es el techo al que se rellena; si no se captura, el techo de la sugerencia
            // pasa a ser el propio mínimo.
            'maximo' => ['nullable', 'integer', 'min:0', 'gte:minimo'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'maximo.gte' => 'El máximo no puede ser menor que el mínimo.',
        ];
    }
}
