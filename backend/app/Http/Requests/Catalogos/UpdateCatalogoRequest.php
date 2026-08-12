<?php

namespace App\Http\Requests\Catalogos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCatalogoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre,
            'descuento' => $this->descuento === null || $this->descuento === '' ? 0 : $this->descuento,
            'utilidad_porcentaje' => $this->utilidad_porcentaje === null || $this->utilidad_porcentaje === '' ? 0 : $this->utilidad_porcentaje,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `proveedor_id` no aparece aquí a propósito: es inmutable tras la creación del catálogo (ver
     * 009-catalogos.md), así que aunque el cliente lo envíe en el PUT, no se valida ni se persiste.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:255',
                Rule::unique('catalogos', 'nombre')
                    ->where('proveedor_id', $this->route('catalogo')->proveedor_id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('catalogo')),
            ],
            'descuento' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'utilidad_porcentaje' => ['required', 'numeric', 'gte:0', 'lte:999.99', 'decimal:0,2'],
        ];
    }
}
