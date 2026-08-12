<?php

namespace App\Http\Requests\Proveedores;

use App\Rules\RfcValido;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProveedorRequest extends FormRequest
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
        $telefono = $this->telefono;
        if (is_string($telefono) && trim($telefono) !== '') {
            $digitos = preg_replace('/\D/', '', $telefono);
            if (strlen($digitos) === 12 && str_starts_with($digitos, '52')) {
                $digitos = substr($digitos, 2);
            }
            $telefono = '+52'.$digitos;
        } elseif (is_string($telefono)) {
            $telefono = null;
        }

        $this->merge([
            'nombre_comercial' => is_string($this->nombre_comercial) ? trim($this->nombre_comercial) : $this->nombre_comercial,
            'nombre_contacto' => is_string($this->nombre_contacto) ? trim($this->nombre_contacto) : $this->nombre_contacto,
            'correo' => is_string($this->correo) ? trim($this->correo) : $this->correo,
            'rfc' => is_string($this->rfc) ? strtoupper(trim($this->rfc)) : $this->rfc,
            'telefono' => $telefono,
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
            'nombre_comercial' => ['required', 'string', 'max:255'],
            'nombre_contacto' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'regex:/^\+52\d{10}$/'],
            'rfc' => [
                'nullable',
                'string',
                new RfcValido,
                Rule::unique('proveedores', 'rfc')
                    ->where('user_id', $this->user()->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
