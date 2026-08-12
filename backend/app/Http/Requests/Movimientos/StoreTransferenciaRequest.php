<?php

namespace App\Http\Requests\Movimientos;

use App\Rules\CuentaActivaDelUsuario;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * De cara al usuario una transferencia es una sola operación (origen, destino, monto, fecha), pero
 * se persiste como dos filas de `Movimiento` vinculadas por un `transferencia_id` compartido (ver
 * 010-tesoreria.md).
 *
 * La validación de saldo no negativo sobre la cuenta origen no está aquí sino en TesoreriaService,
 * para poder evaluarla dentro de la transacción con ambas cuentas ya bloqueadas.
 */
class StoreTransferenciaRequest extends FormRequest
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
            'concepto' => is_string($this->concepto) ? trim($this->concepto) : $this->concepto,
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
            'cuenta_origen_id' => ['required', 'integer', new CuentaActivaDelUsuario($this->user()->id)],
            'cuenta_destino_id' => [
                'required',
                'integer',
                'different:cuenta_origen_id',
                new CuentaActivaDelUsuario($this->user()->id),
            ],
            'monto' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'fecha' => ['required', 'date'],
            'concepto' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cuenta_destino_id.different' => 'La cuenta destino debe ser distinta de la cuenta origen.',
        ];
    }
}
