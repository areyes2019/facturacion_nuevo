<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CotizacionResource extends JsonResource
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
            'folio' => $this->folio,
            'estado' => $this->estado->value,
            'cliente_id' => $this->cliente_id,
            'cliente_razon_social' => $this->whenLoaded('cliente', fn () => $this->cliente->razon_social),
            'cliente_rfc' => $this->whenLoaded('cliente', fn () => $this->cliente->rfc),
            'cliente_correo' => $this->whenLoaded('cliente', fn () => $this->cliente->correo_contacto),
            'cliente_telefono' => $this->whenLoaded('cliente', fn () => $this->cliente->telefono),
            // Copia congelada: el descuento que tenía el cliente al capturar esta cotización, no el
            // vigente (ver 015-descuento-permanente-cliente.md).
            'descuento_cliente_porcentaje' => (float) $this->descuento_cliente_porcentaje,
            'descuento_global_tipo' => $this->descuento_global_tipo?->value,
            'descuento_global_valor' => $this->descuento_global_valor !== null ? (float) $this->descuento_global_valor : null,
            'subtotal' => (float) $this->subtotal,
            'total_descuento' => (float) $this->total_descuento,
            'total_iva_16' => (float) $this->total_iva_16,
            'total_iva_0' => (float) $this->total_iva_0,
            'total_exento' => (float) $this->total_exento,
            'total' => (float) $this->total,
            'total_pagado' => (float) $this->totalPagado(),
            'saldo_pendiente' => (float) max(0, $this->total - $this->totalPagado()),
            'factura_id' => $this->factura_id,
            'factura_estado' => $this->whenLoaded('factura', fn () => $this->factura?->estado->value),
            // La regla de borrado y la de caducidad son la misma, evaluada en el servidor: el
            // frontend pinta el botón y el aviso sin reimplementarla (ver 008-cotizaciones.md).
            'puede_eliminarse' => $this->puedeEliminarse(),
            'caduca_el' => $this->caducaEl(),
            'lineas' => CotizacionLineaResource::collection($this->whenLoaded('lineas')),
            'pagos' => CotizacionPagoResource::collection($this->whenLoaded('pagos')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
