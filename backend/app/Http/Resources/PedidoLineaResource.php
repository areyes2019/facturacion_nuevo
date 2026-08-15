<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoLineaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'articulo_id' => $this->articulo_id,
            // El frontend pinta distinto la línea libre (no tiene ficha de artículo que abrir ni
            // existencia que consultar), y esta bandera le evita reimplementar la regla.
            'es_libre' => $this->esLibre(),
            'cantidad' => $this->cantidad,
            'descripcion' => $this->descripcion,
            'modelo' => $this->modelo,
            'precio_unitario' => (float) $this->precio_unitario,
            'descuento_tipo' => $this->descuento_tipo?->value,
            'descuento_valor' => $this->descuento_valor !== null ? (float) $this->descuento_valor : null,
            'tasa_iva' => $this->tasa_iva->value,
            'importe' => (float) $this->importe,
            'iva_importe' => (float) $this->iva_importe,
        ];
    }
}
