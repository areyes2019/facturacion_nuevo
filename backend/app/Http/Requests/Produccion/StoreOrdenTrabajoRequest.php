<?php

namespace App\Http\Requests\Produccion;

use App\Models\Cotizacion;
use App\Models\Pedido;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crea una Orden de Trabajo a partir de un Pedido o una Cotización (ver
 * 038-produccion-ordenes-trabajo.md).
 *
 * `documentable_type` recibe el alias corto ("pedido"/"cotizacion"), nunca el nombre de la clase:
 * mismo criterio que el resto de la API, que no expone FQCN por HTTP.
 */
class StoreOrdenTrabajoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|Closure>
     */
    public function rules(): array
    {
        return [
            'documentable_type' => ['required', Rule::in(['pedido', 'cotizacion'])],
            'documentable_id' => ['required', 'integer', $this->documentoValido()],
        ];
    }

    /**
     * El documento debe ser del usuario, tener al menos un pago registrado, y no tener ya una Orden
     * de Trabajo asociada.
     */
    private function documentoValido(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $documento = $this->resolverDocumento((int) $value);

            if ($documento === null) {
                $fail('El pedido o cotización indicado no existe.');

                return;
            }

            if (! $documento->tienePagos()) {
                $fail('Solo se puede crear una Orden de Trabajo para un documento con al menos un pago registrado.');

                return;
            }

            if ($documento->ordenTrabajo()->exists()) {
                $fail('Este documento ya tiene una Orden de Trabajo.');
            }
        };
    }

    public function resolverDocumento(?int $id = null): Pedido|Cotizacion|null
    {
        $id ??= (int) $this->input('documentable_id');

        return match ($this->input('documentable_type')) {
            'pedido' => $this->user()->pedidos()->find($id),
            'cotizacion' => $this->user()->cotizaciones()->find($id),
            default => null,
        };
    }
}
