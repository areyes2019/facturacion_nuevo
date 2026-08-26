<?php

namespace App\Http\Resources;

use App\Services\PrecioArticuloCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticuloResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $catalogo = $this->whenLoaded('catalogo');

        return [
            'id' => $this->id,
            'catalogo_id' => $this->catalogo_id,
            'catalogo_nombre' => $this->whenLoaded('catalogo', fn () => $this->catalogo->nombre),
            'proveedor_id' => $this->whenLoaded('catalogo', fn () => $this->catalogo->proveedor_id),
            'proveedor_nombre_comercial' => $this->whenLoaded(
                'catalogo',
                fn () => $this->catalogo->relationLoaded('proveedor') ? $this->catalogo->proveedor->nombre_comercial : null
            ),
            'nombre' => $this->nombre,
            'modelo' => $this->modelo,
            'clave_prod_serv' => $this->clave_prod_serv,
            'clave_unidad' => $this->clave_unidad,
            'objeto_imp' => $this->objeto_imp->value,
            'precio_proveedor' => (float) $this->precio_proveedor,
            'utilidad_porcentaje' => $this->utilidad_porcentaje === null ? null : (float) $this->utilidad_porcentaje,
            'utilidad_porcentaje_efectivo' => $this->whenLoaded(
                'catalogo',
                fn () => PrecioArticuloCalculator::utilidadEfectiva($this->resource, $this->catalogo)
            ),
            'utilidad_distribuidor_porcentaje' => $this->utilidad_distribuidor_porcentaje === null ? null : (float) $this->utilidad_distribuidor_porcentaje,
            'utilidad_distribuidor_porcentaje_efectivo' => $this->whenLoaded(
                'catalogo',
                fn () => PrecioArticuloCalculator::utilidadDistribuidorEfectiva($this->resource, $this->catalogo)
            ),
            'tamano_goma' => $this->tamano_goma?->value,
            'tiene_imagen' => filled($this->imagen_ruta),
            // La ruta interna del archivo no se expone: es un detalle del servidor. Lo que el
            // frontend necesita es un valor que cambie con cada reemplazo, para colgarlo de la URL
            // de la imagen y que el navegador no le sirva la anterior desde su caché (ver 020).
            'imagen_version' => $this->imagen_version,
            'costo_goma' => (float) $this->costo_goma,
            'costo_con_descuento' => (float) $this->costo_con_descuento,
            'costo_total' => $this->costo_total,
            'precio_unitario_sin_iva' => (float) $this->precio_unitario_sin_iva,
            'precio_unitario_con_iva' => $this->precio_unitario_con_iva,
            'precio_distribuidor_sin_iva' => (float) $this->precio_distribuidor_sin_iva,
            'precio_distribuidor_con_iva' => $this->precio_distribuidor_con_iva,
            'utilidad' => $this->utilidad,
            'utilidad_distribuidor' => $this->utilidad_distribuidor,
            // Ver 017-inventario.md: si el artículo tiene fila en `existencias`, está "en
            // existencias" — la acción rápida de la lista general de Artículos se apoya en esto.
            'en_existencias' => $this->when($this->relationLoaded('existencia'), fn () => $this->existencia !== null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
