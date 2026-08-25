<?php

namespace App\Http\Requests\Cotizaciones;

use App\Enums\EstadoCotizacion;
use App\Models\Cotizacion;
use App\Rules\CuentaActivaDelUsuario;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre de la cotización al escanear su QR (ver 038-produccion-ordenes-trabajo.md, que extiende a
 * Cotización el mismo mecanismo de `EntregarPedidoRequest`, 027).
 *
 * El monto no viaja en la petición: lo calcula el backend como el saldo exacto. `cuenta_id` es
 * obligatorio cuando hay saldo y está prohibido cuando no lo hay.
 */
class EntregarCotizacionRequest extends FormRequest
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
        /** @var Cotizacion $cotizacion */
        $cotizacion = $this->route('cotizacion');

        $cobra = $cotizacion->estado !== EstadoCotizacion::ProductoEntregado && $cotizacion->saldoPendiente() > 0;

        return [
            'cuenta_id' => $cobra
                ? ['required', 'integer', new CuentaActivaDelUsuario($this->user()->id)]
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cuenta_id.required' => 'Elige la cuenta a la que entra el dinero.',
            'cuenta_id.prohibited' => 'Esta cotización no tiene saldo pendiente, así que no hay nada que cobrar.',
        ];
    }
}
