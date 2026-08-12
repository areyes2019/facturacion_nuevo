<?php

namespace App\Http\Requests\Catalogos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AumentarCostosRequest extends FormRequest
{
    /**
     * Tope del aumento por operación. Duplicar el costo de un catálogo entero ya es un movimiento
     * extremo; el tope está para que un dedazo tipo "500" no se aplique sin más. Si alguna vez hace
     * falta más, se hace en dos pasos (ver 021-mantenimiento-articulos-catalogos.md).
     */
    public const MAXIMO_AUMENTO = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `gt:0` porque esta operación solo sube: bajar costos se hace editando el artículo o el
     * descuento del catálogo. Dos decimales, el mismo límite que ya tienen `descuento` y
     * `utilidad_porcentaje`, porque los proveedores no suben en números redondos.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'aumento_porcentaje' => ['required', 'numeric', 'gt:0', 'lte:'.self::MAXIMO_AUMENTO, 'decimal:0,2'],
        ];
    }
}
