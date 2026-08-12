<?php

namespace App\Http\Requests\Cotizaciones;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solo aplica a cotizaciones en estado `borrador`/`enviada` (el controller valida el estado); si
 * estaba `enviada`, guardar la regresa a `borrador` (ver 008-cotizaciones.md, supuesto #18).
 */
class UpdateCotizacionRequest extends FormRequest
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
            'cliente_id' => [
                'required',
                'integer',
                Rule::exists('clientes', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
            ],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.articulo_id' => [
                'required',
                'integer',
                Rule::exists('articulos', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
            ],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1'],
            'lineas.*.descripcion' => ['required', 'string', 'max:255'],
            'lineas.*.modelo' => ['required', 'string', 'max:255'],
            'lineas.*.precio_unitario' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'lineas.*.descuento_tipo' => ['nullable', 'in:porcentaje,monto'],
            'lineas.*.descuento_valor' => ['nullable', 'numeric', 'gte:0', $this->limiteDescuentoPorcentual()],
            'lineas.*.tasa_iva' => ['required', 'in:16,0,exento'],
            'descuento_global_tipo' => ['nullable', 'in:porcentaje,monto'],
            'descuento_global_valor' => ['nullable', 'numeric', 'gte:0', $this->limiteDescuentoGlobalPorcentual()],
            'total' => ['required', 'numeric'],
        ];
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
