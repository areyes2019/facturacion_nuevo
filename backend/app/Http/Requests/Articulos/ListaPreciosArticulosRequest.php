<?php

namespace App\Http\Requests\Articulos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListaPreciosArticulosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mismo criterio que `EliminarLoteArticulosRequest` (ver 028-lista-precios-pdf.md): el scopeo
     * al usuario va en la regla, para que un `id` ajeno o inexistente rechace la petición completa
     * con `422` y no genere un PDF con "lo que sí existe".
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
            // Sin valor por defecto en el servidor: lo decide siempre el frontend (preseleccionado
            // a "distribuidor" en el selector), para no tener un default silencioso en dos capas
            // distintas que se puedan desincronizar.
            'tipo' => ['required', 'string', Rule::in(['distribuidor', 'publico'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Selecciona al menos un artículo.',
            'ids.min' => 'Selecciona al menos un artículo.',
            'ids.*.exists' => 'Alguno de los artículos seleccionados ya no existe. Recarga la lista e inténtalo de nuevo.',
            'tipo.required' => 'Indica qué tipo de precio lleva la lista.',
            'tipo.in' => 'El tipo de precio debe ser "distribuidor" o "publico".',
        ];
    }
}
