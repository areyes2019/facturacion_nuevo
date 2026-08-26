<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un renglón de la pantalla de Existencias (ver 017-inventario.md).
 *
 * El recurso envuelve una `Existencia` (la fila de la bodega curada), apoyada en su `Articulo`
 * (`whenLoaded('articulo')`) para los datos maestros — nombre, modelo, catálogo, costo, precio.
 *
 * `id` es el **id del artículo**, no el id interno de la fila de `existencias`: los endpoints de
 * ajuste, parámetros, movimientos y quitar-de-existencias rutean todos sobre `{articulo}`, así que
 * el frontend solo necesita conocer un identificador por renglón.
 */
class InventarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->articulo_id,
            'nombre' => $this->whenLoaded('articulo', fn () => $this->articulo->nombre),
            'modelo' => $this->whenLoaded('articulo', fn () => $this->articulo->modelo),
            'catalogo_id' => $this->whenLoaded('articulo', fn () => $this->articulo->catalogo_id),
            'catalogo_nombre' => $this->whenLoaded('articulo', fn () => $this->articulo->relationLoaded('catalogo') ? $this->articulo->catalogo?->nombre : null),
            'proveedor_id' => $this->whenLoaded('articulo', fn () => $this->articulo->relationLoaded('catalogo') ? $this->articulo->catalogo?->proveedor_id : null),
            'proveedor_nombre_comercial' => $this->whenLoaded('articulo', function () {
                $catalogo = $this->articulo->relationLoaded('catalogo') ? $this->articulo->catalogo : null;

                return $catalogo?->relationLoaded('proveedor') ? $catalogo->proveedor?->nombre_comercial : null;
            }),

            'existencia' => (int) $this->existencia,
            'faltante_pendiente' => (int) $this->faltante_pendiente,
            'minimo' => (int) $this->minimo,
            'maximo' => $this->maximo === null ? null : (int) $this->maximo,

            'costo_total' => $this->whenLoaded('articulo', fn () => $this->articulo->costo_total),
            'precio_unitario_sin_iva' => $this->whenLoaded('articulo', fn () => (float) $this->articulo->precio_unitario_sin_iva),
            'utilidad' => $this->whenLoaded('articulo', fn () => $this->articulo->utilidad),

            'dinero_invertido' => $this->dinero_invertido,
            'beneficio_potencial' => $this->beneficio_potencial,
            'por_pedir' => $this->por_pedir,
            'cantidad_sugerida' => $this->cantidad_sugerida,
        ];
    }
}
