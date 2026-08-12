<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogoResource extends JsonResource
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
            'proveedor_id' => $this->proveedor_id,
            'proveedor_nombre_comercial' => $this->whenLoaded('proveedor', fn () => $this->proveedor->nombre_comercial),
            'nombre' => $this->nombre,
            'descuento' => (float) $this->descuento,
            'utilidad_porcentaje' => (float) $this->utilidad_porcentaje,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
