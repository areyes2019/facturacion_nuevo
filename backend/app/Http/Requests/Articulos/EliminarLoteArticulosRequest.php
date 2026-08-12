<?php

namespace App\Http\Requests\Articulos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EliminarLoteArticulosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El scopeo al usuario va **en la regla**, no en el controlador: un identificador ajeno o
     * inexistente rechaza la petición completa con `422` antes de abrir la transacción, y no se
     * borra ninguno de los demás (ver 021-mantenimiento-articulos-catalogos.md).
     *
     * Borrar "lo que sí se pudo" sería exactamente el borrado parcial silencioso que la transacción
     * está evitando.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => [
                'integer',
                Rule::exists('articulos', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * El mensaje por defecto ("The selected ids.2 is invalid") no le dice nada a quien lo lee en
     * pantalla: el caso real es una página abierta hace rato, con artículos que ya no están.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Selecciona al menos un artículo.',
            'ids.min' => 'Selecciona al menos un artículo.',
            'ids.*.exists' => 'Alguno de los artículos seleccionados ya no existe. Recarga la lista e inténtalo de nuevo.',
        ];
    }
}
