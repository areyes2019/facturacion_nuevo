<?php

namespace App\Http\Requests\Movimientos;

use App\Enums\TipoMovimiento;
use App\Rules\CuentaActivaDelUsuario;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Movimiento manual de una sola cuenta. `transferencia` no se acepta aquí: tiene forma y
 * validaciones propias (dos cuentas, dos filas persistidas) y vive en su propio endpoint
 * `POST /api/v1/transferencias` (ver 010-tesoreria.md).
 *
 * La validación de saldo no negativo no está en este Form Request sino en TesoreriaService, para
 * poder evaluarla dentro de la transacción con la cuenta ya bloqueada.
 */
class StoreMovimientoRequest extends FormRequest
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
            'tipo' => ['required', Rule::in([
                TipoMovimiento::Ingreso->value,
                TipoMovimiento::Egreso->value,
                TipoMovimiento::Ajuste->value,
            ])],
            'cuenta_id' => ['required', 'integer', new CuentaActivaDelUsuario($this->user()->id)],
            'monto' => [
                'required',
                'numeric',
                'decimal:0,2',
                // Un ajuste corrige de más o de menos, así que acepta negativos; lo único que no
                // tiene sentido en ningún tipo es un monto de cero.
                $this->input('tipo') === TipoMovimiento::Ajuste->value ? 'not_in:0' : 'gt:0',
            ],
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
            'monto.not_in' => 'El monto de un ajuste no puede ser cero.',
        ];
    }
}
