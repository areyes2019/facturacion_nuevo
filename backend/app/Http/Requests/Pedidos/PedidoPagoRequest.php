<?php

namespace App\Http\Requests\Pedidos;

use App\Models\Pedido;
use App\Rules\CuentaActivaDelUsuario;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de un pago de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * Sin `tipo`, a diferencia de `CotizacionPagoRequest`: no hay "anticipo" ni "saldo" que declarar,
 * porque el estado del pedido lo decide la suma de los pagos. Y sin porcentaje mínimo: el usuario
 * captura el monto que recibió, sea el total o cien pesos.
 */
class PedidoPagoRequest extends FormRequest
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
        /** @var Pedido $pedido */
        $pedido = $this->route('pedido');

        return [
            'fecha_pago' => ['required', 'date'],
            'monto' => ['required', 'numeric', 'gt:0', 'decimal:0,2', $this->sinSobrepago($pedido)],
            'cuenta_id' => ['required', 'integer', new CuentaActivaDelUsuario($this->user()->id)],
        ];
    }

    /**
     * Cobrar de más no es un pago: es una captura equivocada que después habría que corregir a mano
     * en Tesorería.
     */
    private function sinSobrepago(Pedido $pedido): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pedido) {
            if ((float) $value > $pedido->saldoPendiente()) {
                $fail('El monto no puede superar el saldo pendiente del pedido.');
            }
        };
    }
}
