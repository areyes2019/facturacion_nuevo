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
            // Distingue el cobro que hizo el escaneo del QR de los que capturó el usuario. El
            // historial tiene que poder explicar de dónde salió cada peso.
            'automatico' => (bool) $this->automatico,
            'created_at' => $this->created_at,
        ];
    }
}
