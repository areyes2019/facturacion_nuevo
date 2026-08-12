<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CotizacionPagoResource extends JsonResource
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
            'tipo' => $this->tipo->value,
            'fecha_pago' => $this->fecha_pago->toDateString(),
            'monto' => (float) $this->monto,
            // Expone la cuenta de Tesorería en la que entró el dinero, en lugar del `forma_pago`
            // del catálogo SAT que existía antes (ver 010-tesoreria.md).
            'cuenta_id' => $this->cuenta_id,
            'cuenta_nombre' => $this->whenLoaded('cuenta', fn () => $this->cuenta?->nombre),
            'created_at' => $this->created_at,
        ];
    }
}
