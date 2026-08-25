<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnvioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre_receptor' => $this->nombre_receptor,
            'telefono_receptor' => $this->telefono_receptor,
            'fecha_recepcion' => $this->fecha_recepcion,
            'hora_recepcion' => $this->hora_recepcion,
            'tarifa' => $this->tarifa->value,
            'monto' => (float) $this->monto,
            'forma_pago' => $this->forma_pago->value,
            'created_at' => $this->created_at,
        ];
    }
}
