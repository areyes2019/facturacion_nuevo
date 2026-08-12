<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CotizacionLineaResource extends JsonResource
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
            'articulo_id' => $this->articulo_id,
            'cantidad' => $this->cantidad,
            'descripcion' => $this->descripcion,
            'modelo' => $this->modelo,
            'precio_unitario' => (float) $this->precio_unitario,
            // Precio con el descuento de línea ya plegado adentro, para precargar la factura sin
            // que el descuento sea visible (ver 015-descuento-permanente-cliente.md).
            'precio_unitario_facturacion' => $this->precio_unitario_facturacion,
            'descuento_tipo' => $this->descuento_tipo?->value,
            'descuento_valor' => $this->descuento_valor !== null ? (float) $this->descuento_valor : null,
            'tasa_iva' => $this->tasa_iva->value,
            'importe' => (float) $this->importe,
            'iva_importe' => (float) $this->iva_importe,
        ];
    }
}
