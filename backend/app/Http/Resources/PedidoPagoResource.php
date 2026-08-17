<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoPagoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fecha_pago' => $this->fecha_pago->toDateString(),
            'monto' => (float) $this->monto,
            'cuenta_id' => $this->cuenta_id,
            'cuenta_nombre' => $this->whenLoaded('cuenta', fn () => $this->cuenta?->nombre),
            // Distingue el pago que cerró la venta de los que se capturaron en el mostrador. El
            // historial tiene que poder explicar de dónde salió cada peso.
            'registrado_al_entregar' => (bool) $this->registrado_al_entregar,
            'created_at' => $this->created_at,
        ];
    }
}
