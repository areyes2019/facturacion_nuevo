<?php

namespace App\Http\Requests\OrdenesCompra;

use App\Rules\CuentaActivaDelUsuario;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El pago a proveedores es siempre de contado: un solo pago, por el total de la orden (ver
 * 012-ordenes-compra.md, supuesto #21). Por eso aquí no hay `monto`: el controller siempre usa el
 * `total` de la orden e ignora en silencio cualquier valor que se envíe, mismo patrón que los
 * valores derivados de 011.
 */
class PagarOrdenCompraRequest extends FormRequest
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
            // La cuenta debe estar activa porque el pago genera un movimiento automático de egreso
            // (ver 010-tesoreria.md).
            'cuenta_id' => ['required', 'integer', new CuentaActivaDelUsuario($this->user()->id)],
            'fecha_pago' => ['required', 'date'],
        ];
    }
}
