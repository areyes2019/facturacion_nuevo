<?php

namespace App\Http\Requests\DatosBancarios;

use App\Models\DatoBancario;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Nuevo orden de la lista de bancos (ver 026-datos-bancarios-cotizacion.md).
 */
class ReordenarDatosBancariosRequest extends FormRequest
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
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ];
    }

    /**
     * La lista tiene que traer **exactamente** los bancos que existen, ni uno más ni uno menos.
     *
     * Un reordenamiento parcial dejaría posiciones repetidas entre los enviados y los que se
     * quedaron, y el orden de impresión pasaría a depender del desempate por `id` en vez de lo que
     * el usuario arrastró. Es un error del cliente, no del usuario, y por eso el mensaje pide
     * recargar en vez de explicar la regla.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $enviados = array_map('intval', $this->input('ids'));
                $existentes = DatoBancario::query()->pluck('id')->map('intval')->all();

                sort($enviados);
                sort($existentes);

                if ($enviados !== $existentes) {
                    $validator->errors()->add(
                        'ids',
                        'La lista de bancos cambió. Recarga la página e inténtalo de nuevo.',
                    );
                }
            },
        ];
    }
}
