<?php

namespace App\Http\Requests\Cuentas;

use App\Enums\TipoCuenta;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCuentaRequest extends FormRequest
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
            'saldo_inicial' => $this->saldo_inicial === null || $this->saldo_inicial === '' ? 0 : $this->saldo_inicial,
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
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoCuenta::class)],
            'saldo_inicial' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'activa' => ['sometimes', 'boolean'],
        ];
    }
}
