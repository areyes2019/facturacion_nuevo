<?php

namespace App\Http\Requests\Pedidos;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un pedido de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * Dos diferencias contra `StoreCotizacionRequest`, y las dos son de fondo:
 *
 * - **No hay `cliente_id`.** El cliente de mostrador se captura aquí mismo, sin RFC.
 * - **`lineas.*.articulo_id` es `nullable`.** Esa es la línea libre: algo que se vendió y no está
 *   en el catálogo. `descripcion` sigue siendo obligatoria porque en una línea libre es lo único
 *   que la identifica.
 */
class StorePedidoRequest extends FormRequest
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
            'cliente_nombre' => ['required', 'string', 'max:150'],
            'cliente_telefono' => ['required', 'string', 'max:30'],
            'cliente_correo' => ['nullable', 'email', 'max:255'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.articulo_id' => [
                'nullable',
                'integer',
                Rule::exists('articulos', 'id')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
            ],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1'],
            'lineas.*.descripcion' => ['required', 'string', 'max:255'],
            'lineas.*.modelo' => ['nullable', 'string', 'max:255'],
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
