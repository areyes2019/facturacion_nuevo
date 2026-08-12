<?php

namespace App\Http\Requests\Clientes;

use App\Rules\CodigoPostalValido;
use App\Rules\RegimenFiscalValido;
use App\Rules\RfcValido;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClienteRequest extends FormRequest
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
            'rfc' => is_string($this->rfc) ? strtoupper(trim($this->rfc)) : $this->rfc,
            'regimen_fiscal' => is_string($this->regimen_fiscal) ? trim($this->regimen_fiscal) : $this->regimen_fiscal,
            'codigo_postal_fiscal' => is_string($this->codigo_postal_fiscal) ? trim($this->codigo_postal_fiscal) : $this->codigo_postal_fiscal,
        ]);

        // Mismo criterio que en el alta: en blanco equivale a 0% (ver
        // 015-descuento-permanente-cliente.md). Ausente no toca el valor guardado.
        if ($this->has('descuento_permanente') && in_array($this->descuento_permanente, [null, ''], true)) {
            $this->merge(['descuento_permanente' => 0]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rfc' => [
                'required',
                'string',
                new RfcValido,
                Rule::unique('clientes', 'rfc')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at')
                    ->ignore($this->route('cliente')),
            ],
            'razon_social' => ['required', 'string', 'max:255'],
            'regimen_fiscal' => ['required', 'string', new RegimenFiscalValido],
            'codigo_postal_fiscal' => ['required', 'digits:5', new CodigoPostalValido],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            'correo_contacto' => ['nullable', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion_comercial' => ['nullable', 'string', 'max:255'],
            'descuento_permanente' => ['sometimes', 'numeric', 'min:0', 'max:50', 'decimal:0,2'],
        ];
    }
}
