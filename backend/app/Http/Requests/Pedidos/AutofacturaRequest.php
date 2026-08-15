<?php

namespace App\Http\Requests\Pedidos;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use PhpCfdi\Rfc\Rfc;

/**
 * Datos fiscales que captura el propio cliente en el portal público de autofactura (ver
 * 027-venta-mostrador-ticket.md).
 *
 * Es el único Form Request del sistema que corre **sin sesión**, así que no puede apoyarse en
 * `$this->user()` para nada: todo lo que valida sale de lo que se envía o del pedido que trae el
 * token de la ruta.
 */
class AutofacturaRequest extends FormRequest
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
            'rfc' => ['required', 'string', 'max:13', $this->rfcValido()],
            'razon_social' => ['required', 'string', 'max:255'],
            'regimen_fiscal' => ['required', 'string', 'max:5'],
            'codigo_postal_fiscal' => ['required', 'string', 'regex:/^\d{5}$/'],
            'uso_cfdi' => ['required', 'string', 'max:5'],
            'correo' => ['required', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo_postal_fiscal.regex' => 'El código postal debe tener 5 dígitos.',
        ];
    }

    /**
     * Se valida con la misma librería que ya usa el alta de clientes: un RFC mal formado rebotaría
     * igual en el timbrado, pero ahí el cliente vería un error del SAT en vez de una frase clara.
     */
    private function rfcValido(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (Rfc::parseOrNull(strtoupper(trim((string) $value))) === null) {
                $fail('El RFC no tiene un formato válido.');
            }
        };
    }
}
