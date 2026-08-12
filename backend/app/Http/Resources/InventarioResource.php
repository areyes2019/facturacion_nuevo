<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un renglón de la pantalla de Existencias (ver 017-inventario.md).
 *
 * Es una vista del `Articulo` centrada en la operación diaria —cuánto hay, cuánto falta, cuándo
 * reponer y cuánto dinero representa—, no en sus datos maestros, que siguen viviendo en
 * `ArticuloResource`.
 */
class InventarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'modelo' => $this->modelo,
            'catalogo_id' => $this->catalogo_id,
            'catalogo_nombre' => $this->whenLoaded('catalogo', fn () => $this->catalogo->nombre),
            'proveedor_id' => $this->whenLoaded('catalogo', fn () => $this->catalogo->proveedor_id),
            'proveedor_nombre_comercial' => $this->whenLoaded(
                'catalogo',
                fn () => $this->catalogo->relationLoaded('proveedor') ? $this->catalogo->proveedor?->nombre_comercial : null
            ),

            'existencia' => (int) $this->existencia,
            'faltante_pendiente' => (int) $this->faltante_pendiente,
            'minimo' => (int) $this->minimo,
            'maximo' => $this->maximo === null ? null : (int) $this->maximo,

            'costo_total' => $this->costo_total,
            'precio_unitario_sin_iva' => (float) $this->precio_unitario_sin_iva,
            'utilidad' => $this->utilidad,

            'dinero_invertido' => $this->dinero_invertido,
            'beneficio_potencial' => $this->beneficio_potencial,
            'por_pedir' => $this->por_pedir,
            'cantidad_sugerida' => $this->cantidad_sugerida,
        ];
    }
}
