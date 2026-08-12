<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProveedorResource extends JsonResource
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
            'nombre_comercial' => $this->nombre_comercial,
            'nombre_contacto' => $this->nombre_contacto,
            'correo' => $this->correo,
            'telefono' => $this->telefono,
            'rfc' => $this->rfc,
            // Derivado por consulta, no una columna (ver 012-ordenes-compra.md, adición técnica
            // 37): el campo que expone la API no cambia de forma ni de nombre.
            'tiene_ordenes_activas' => $this->tieneOrdenesActivas(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
