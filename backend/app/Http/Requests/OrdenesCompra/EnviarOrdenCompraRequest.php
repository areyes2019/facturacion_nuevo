<?php

namespace App\Http\Requests\OrdenesCompra;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnviarOrdenCompraRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'canal' => ['required', 'in:correo,whatsapp'],
            'destinatarios' => [Rule::requiredIf($this->input('canal') === 'correo'), 'array', 'min:1'],
            'destinatarios.*' => ['required', 'email'],
            'telefono' => [Rule::requiredIf($this->input('canal') === 'whatsapp'), 'string', 'max:20'],
        ];
    }
}
