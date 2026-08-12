<?php

namespace App\Http\Requests\Cuentas;

use App\Enums\TipoCuenta;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCuentaRequest extends FormRequest
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
            'nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `saldo_inicial` no aparece aquí a propósito: es inmutable tras la creación de la cuenta (ver
     * 010-tesoreria.md), así que aunque el cliente lo envíe en el PUT, no se valida ni se persiste
     * — mismo patrón que `proveedor_id` de `Catalogo` en 009. Para corregirlo se registra un
     * Ajuste, que deja rastro en el historial de movimientos.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoCuenta::class)],
            'activa' => ['sometimes', 'boolean'],
        ];
    }
}
