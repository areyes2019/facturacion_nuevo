<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenCompraResource extends JsonResource
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
            'folio_formateado' => $this->folioFormateado(),
            'estado' => $this->estado->value,
            'proveedor_id' => $this->proveedor_id,
            'proveedor_nombre_comercial' => $this->whenLoaded('proveedor', fn () => $this->proveedor->nombre_comercial),
            'proveedor_rfc' => $this->whenLoaded('proveedor', fn () => $this->proveedor->rfc),
            'proveedor_correo' => $this->whenLoaded('proveedor', fn () => $this->proveedor->correo),
            'proveedor_telefono' => $this->whenLoaded('proveedor', fn () => $this->proveedor->telefono),
            'fecha_entrega_esperada' => $this->fecha_entrega_esperada?->toDateString(),
            'observaciones' => $this->observaciones,
            'descuento_global_tipo' => $this->descuento_global_tipo?->value,
            'descuento_global_valor' => $this->descuento_global_valor !== null ? (float) $this->descuento_global_valor : null,
            'subtotal' => (float) $this->subtotal,
            'total_descuento' => (float) $this->total_descuento,
            'total_iva_16' => (float) $this->total_iva_16,
            'total_iva_0' => (float) $this->total_iva_0,
            'total_exento' => (float) $this->total_exento,
            'total' => (float) $this->total,
            // Pago de contado: único y por el total, así que son dos campos de la orden y no un
            // historial (ver 012-ordenes-compra.md, adición técnica 35).
            'cuenta_id' => $this->cuenta_id,
            'cuenta_nombre' => $this->whenLoaded('cuenta', fn () => $this->cuenta?->nombre),
            'fecha_pago' => $this->fecha_pago?->toDateString(),
            'esta_pagada' => $this->estaPagada(),
            'lineas' => OrdenCompraLineaResource::collection($this->whenLoaded('lineas')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
