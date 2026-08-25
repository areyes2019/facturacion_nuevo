<?php

namespace App\Http\Requests\Produccion;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Edita las observaciones de una Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md). */
class UpdateOrdenTrabajoRequest extends FormRequest
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
        return [
            'observaciones' => ['present', 'nullable', 'string', 'max:2000'],
        ];
    }
}
