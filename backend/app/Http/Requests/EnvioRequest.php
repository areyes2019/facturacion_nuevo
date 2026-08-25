<?php

namespace App\Http\Requests;

use App\Enums\FormaPagoEnvio;
use App\Enums\TarifaEnvio;
use App\Rules\CuentaActivaDelUsuario;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Captura un envío a domicilio, colgado de una Orden de Trabajo (038) o directamente de una
 * Cotización de cliente distribuidor (041-envio-domicilio-direccion-y-distribuidor.md). Mismas
 * reglas en ambos casos.
 *
 * `cuenta_id` es obligatorio solo cuando el envío es `prepagado` (a qué cuenta entra el dinero) y
 * está prohibido cuando es `por_cobrar` — ese dinero nunca pasa por el negocio, y recibir una cuenta
 * ahí solo podría significar que alguien entendió mal (mismo criterio que `EntregarPedidoRequest`).
 */
class EnvioRequest extends FormRequest
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
        $prepagado = $this->input('forma_pago') === FormaPagoEnvio::Prepagado->value;

        return [
            'nombre_receptor' => ['required', 'string', 'max:150'],
            'telefono_receptor' => ['required', 'string', 'max:30'],
            'direccion' => ['required', 'string', 'max:255'],
            'fecha_recepcion' => ['required', 'date'],
            'hora_recepcion' => ['required', 'date_format:H:i'],
            'tarifa' => ['required', Rule::enum(TarifaEnvio::class)],
            'forma_pago' => ['required', Rule::enum(FormaPagoEnvio::class)],
            'cuenta_id' => $prepagado
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
            'cuenta_id.required' => 'Elige la cuenta a la que entra el dinero del envío prepagado.',
            'cuenta_id.prohibited' => 'Un envío "por cobrar" no se registra en ninguna cuenta: lo cobra el repartidor.',
        ];
    }
}
