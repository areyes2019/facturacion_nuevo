<?php

namespace App\Http\Requests\Facturas;

use App\Enums\MotivoCancelacion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelarFacturaRequest extends FormRequest
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
            'motivo_cancelacion' => ['required', Rule::enum(MotivoCancelacion::class)],
            'factura_sustituta_id' => [
                Rule::requiredIf(fn () => $this->motivo_cancelacion === MotivoCancelacion::ComprobanteConErroresConRelacion->value),
                'nullable',
                'integer',
                Rule::exists('facturas', 'id')
                    ->where('user_id', $this->user()->id)
                    ->where('estado', 'timbrada'),
            ],
        ];
    }
}
