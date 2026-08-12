<?php

namespace App\Http\Requests\Inventario;

use App\Enums\MotivoMovimientoInventario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajuste manual de existencia (ver 017-inventario.md).
 *
 * `cantidad` es la cantidad **final** que queda, no la diferencia: contaste 10, escribes 10. Cero
 * es válido — "no me queda ninguno" es una respuesta legítima de un conteo.
 */
class AjustarInventarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cantidad' => ['required', 'integer', 'min:0'],
            // Solo motivos manuales: los automáticos (`recepcion_orden`, `venta_*`,
            // `cancelacion_factura`) los escribe el sistema y aceptarlos aquí permitiría falsificar
            // el origen de un movimiento.
            'motivo' => ['required', Rule::in(MotivoMovimientoInventario::valoresManuales())],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.in' => 'El motivo debe ser uno de los motivos manuales: conteo físico, merma, devolución, entrada inicial u otro.',
        ];
    }
}
