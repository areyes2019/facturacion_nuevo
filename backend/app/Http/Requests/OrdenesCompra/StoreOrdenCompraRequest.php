<?php

namespace App\Http\Requests\OrdenesCompra;

use App\Models\Articulo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrdenCompraRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'proveedor_id' => [
                'required',
                'integer',
                Rule::exists('proveedores', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
            ],
            'fecha_entrega_esperada' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.articulo_id' => [
                'required',
                'integer',
                Rule::exists('articulos', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
                $this->articuloDelProveedor(),
            ],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1'],
            'lineas.*.descripcion' => ['required', 'string', 'max:255'],
            'lineas.*.modelo' => ['required', 'string', 'max:255'],
            'lineas.*.precio_unitario' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'lineas.*.descuento_tipo' => ['nullable', 'in:porcentaje,monto'],
            'lineas.*.descuento_valor' => ['nullable', 'numeric', 'gte:0', $this->limiteDescuentoPorcentual()],
            'lineas.*.tasa_iva' => ['required', 'in:16,exento,0'],
            'descuento_global_tipo' => ['nullable', 'in:porcentaje,monto'],
            'descuento_global_valor' => ['nullable', 'numeric', 'gte:0', $this->limiteDescuentoGlobalPorcentual()],
            'total' => ['required', 'numeric'],
        ];
    }

    /**
     * Le compras a un proveedor lo que ese proveedor vende: el artículo tiene que estar en alguno
     * de sus catálogos (009 liga Catálogo → Proveedor). Es la misma regla que aplica el selector
     * del frontend, revalidada en el servidor.
     */
    private function articuloDelProveedor(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $proveedorId = $this->input('proveedor_id');

            if (! $proveedorId) {
                return;
            }

            $pertenece = Articulo::where('id', $value)
                ->whereHas('catalogo', fn ($query) => $query->where('proveedor_id', $proveedorId))
                ->exists();

            if (! $pertenece) {
                $fail('El artículo no pertenece a un catálogo del proveedor seleccionado.');
            }
        };
    }

    private function limiteDescuentoPorcentual(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $tipoAttribute = str_replace('descuento_valor', 'descuento_tipo', $attribute);
            if ($this->input($tipoAttribute) === 'porcentaje' && (float) $value > 100) {
                $fail('El descuento porcentual de la línea no puede ser mayor a 100%.');
            }
        };
    }

    private function limiteDescuentoGlobalPorcentual(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if ($this->input('descuento_global_tipo') === 'porcentaje' && (float) $value > 100) {
                $fail('El descuento global porcentual no puede ser mayor a 100%.');
            }
        };
    }
}
