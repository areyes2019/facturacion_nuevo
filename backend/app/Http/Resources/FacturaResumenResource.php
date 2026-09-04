<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versión chica de `FacturaResource`, para listar las facturas de una cotización
 * (ver 043-facturas-parciales-cotizacion.md) sin cargar líneas ni complemento de pago, que ahí no
 * hacen falta.
 */
class FacturaResumenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'estado' => $this->estado->value,
            'total' => (float) $this->total,
            'uuid_fiscal' => $this->uuid_fiscal,
            'fecha_timbrado' => $this->fecha_timbrado,
            'error_timbrado' => $this->error_timbrado,
        ];
    }
}
