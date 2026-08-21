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
            'utilidad_distribuidor_porcentaje' => (float) $this->utilidad_distribuidor_porcentaje,
            // Cuántos artículos se lleva por delante el borrado del catálogo (ver
            // 021-mantenimiento-articulos-catalogos.md). `whenCounted` lo omite donde no se cargó.
            'articulos_count' => $this->whenCounted('articulos'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
