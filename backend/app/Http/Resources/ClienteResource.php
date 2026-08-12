<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClienteResource extends JsonResource
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
            'rfc' => $this->rfc,
            'razon_social' => $this->razon_social,
            'regimen_fiscal' => $this->regimen_fiscal,
            'codigo_postal_fiscal' => $this->codigo_postal_fiscal,
            'tipo_persona' => $this->tipo_persona,
            'nombre_comercial' => $this->nombre_comercial,
            'correo_contacto' => $this->correo_contacto,
            'telefono' => $this->telefono,
            'direccion_comercial' => $this->direccion_comercial,
            'descuento_permanente' => (float) $this->descuento_permanente,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
