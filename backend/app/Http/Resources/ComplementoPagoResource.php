<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplementoPagoResource extends JsonResource
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
            'factura_id' => $this->factura_id,
            'fecha_pago' => $this->fecha_pago->toDateString(),
            'monto' => (float) $this->monto,
            'forma_pago' => $this->forma_pago,
            'estado' => $this->estado->value,
            'uuid_fiscal' => $this->uuid_fiscal,
            'cadena_original_sat' => $this->cadena_original_sat,
            'error_timbrado' => $this->error_timbrado,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
