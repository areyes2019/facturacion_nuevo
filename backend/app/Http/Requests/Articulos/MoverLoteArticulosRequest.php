<?php

namespace App\Http\Requests\Articulos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoverLoteArticulosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mismo criterio que `EliminarLoteArticulosRequest` (ver
     * 021-mantenimiento-articulos-catalogos.md): el scopeo al usuario va en la regla, no en el
     * controlador, para que un `id` o un `catalogo_id` ajenos rechacen la petición completa con
     * `422` antes de abrir la transacción, sin mover ninguno de los demás
     * (ver 034-filtro-catalogo-y-mover-lote-articulos.md).
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
            'catalogo_id' => [
                'required',
                'integer',
                Rule::exists('catalogos', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
            ],
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
            'catalogo_id.required' => 'Elige el catálogo destino.',
            'catalogo_id.exists' => 'El catálogo elegido ya no existe. Recarga la lista e inténtalo de nuevo.',
        ];
    }
}
