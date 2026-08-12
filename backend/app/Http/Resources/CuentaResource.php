<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CuentaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo->value,
            'tipo_texto' => $this->tipo->texto(),
            'saldo_inicial' => (float) $this->saldo_inicial,
            'saldo_actual' => (float) $this->saldo_actual,
            'activa' => (bool) $this->activa,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
