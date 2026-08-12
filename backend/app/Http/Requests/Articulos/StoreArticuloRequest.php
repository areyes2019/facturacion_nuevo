<?php

namespace App\Http\Requests\Articulos;

use App\Enums\ObjetoImpuesto;
use App\Enums\TamanoGoma;
use App\Models\Catalogo;
use App\Rules\ClaveProdServValido;
use App\Rules\ClaveUnidadValido;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreArticuloRequest extends FormRequest
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
            'modelo' => is_string($this->modelo) ? trim($this->modelo) : $this->modelo,
            'clave_prod_serv' => is_string($this->clave_prod_serv) ? trim($this->clave_prod_serv) : $this->clave_prod_serv,
            'clave_unidad' => is_string($this->clave_unidad) ? strtoupper(trim($this->clave_unidad)) : $this->clave_unidad,
            // Un <select> sin selección manda cadena vacía; se normaliza a null para que se
            // comporte igual que la ausencia del campo (ver 014-costo-elaboracion-goma.md).
            'tamano_goma' => is_string($this->tamano_goma) && trim($this->tamano_goma) === ''
                ? null
                : $this->tamano_goma,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'catalogo_id' => [
                'required',
                'integer',
                Rule::exists('catalogos', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
            ],
            'nombre' => [
                'required',
                'string',
                'max:255',
                // Único por proveedor derivado del catálogo (ver 009-catalogos.md): dos artículos
                // del mismo proveedor no pueden compartir nombre aunque estén en catálogos
                // distintos de ese proveedor.
                Rule::unique('articulos', 'nombre')
                    ->where(function ($query) {
                        $catalogo = Catalogo::find($this->catalogo_id);
                        $query->whereIn('catalogo_id', $catalogo
                            ? Catalogo::query()->where('proveedor_id', $catalogo->proveedor_id)->pluck('id')
                            : []);
                    })
                    ->whereNull('deleted_at'),
            ],
            'modelo' => ['required', 'string', 'max:255'],
            'clave_prod_serv' => ['required', 'string', new ClaveProdServValido],
            'clave_unidad' => ['required', 'string', new ClaveUnidadValido],
            'objeto_imp' => ['required', Rule::enum(ObjetoImpuesto::class)],
            'precio_proveedor' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'utilidad_porcentaje' => ['nullable', 'numeric', 'gte:0', 'lte:999.99', 'decimal:0,2'],
            'tamano_goma' => ['nullable', Rule::enum(TamanoGoma::class)],
        ];
    }
}
