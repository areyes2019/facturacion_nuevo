<?php

namespace App\Http\Requests\Facturas;

use App\Rules\FormaPagoValido;
use App\Rules\UsoCfdiValido;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `cotizacion_id` es opcional: cuando se factura desde una cotización (ver
 * 008-cotizaciones.md), vincula la factura resultante de vuelta a la cotización de origen. Una
 * cotización puede recibir varias facturas (ver 043-facturas-parciales-cotizacion.md); el tope
 * real —que el monto no exceda el saldo pendiente por facturar— se valida en el controlador,
 * porque necesita el total ya calculado de las líneas.
 */
class StoreFacturaRequest extends FormRequest
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
            'uso_cfdi' => ['required', 'string', new UsoCfdiValido],
            'forma_pago' => ['required', 'string', new FormaPagoValido],
            'metodo_pago' => ['required', 'in:PUE,PPD'],
            'descuento_global_tipo' => ['nullable', 'in:porcentaje,monto'],
            'descuento_global_valor' => ['nullable', 'numeric', 'gte:0', $this->limiteDescuentoGlobalPorcentual()],
            'total' => ['required', 'numeric'],
            'cotizacion_id' => [
                'nullable',
                'integer',
                Rule::exists('cotizaciones', 'id')
                    ->where('user_id', $this->user()->id),
            ],
        ];
    }

    /**
     * El descuento por línea, si es porcentual, no puede exceder 100%.
     */
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
