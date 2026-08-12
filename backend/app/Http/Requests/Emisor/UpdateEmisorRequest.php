<?php

namespace App\Http\Requests\Emisor;

use App\Rules\RegimenFiscalValido;
use App\Rules\RfcValido;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'rfc' => is_string($this->rfc) ? strtoupper(trim($this->rfc)) : $this->rfc,
            'regimen_fiscal' => is_string($this->regimen_fiscal) ? trim($this->regimen_fiscal) : $this->regimen_fiscal,
        ]);
    }

    /**
     * Las mismas reglas de RFC y régimen que ya usan clientes y proveedores: el emisor no merece
     * una validación propia ni más laxa que la de su contraparte.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'rfc' => ['required', 'string', new RfcValido],
            'regimen_fiscal' => ['required', 'string', new RegimenFiscalValido],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
        ];
    }
}
