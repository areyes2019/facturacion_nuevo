<?php

namespace App\Http\Resources;

use App\Models\Pedido;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cliente, producto y saldo no viven en `OrdenTrabajo`: se leen aquí del documento origen
 * (`Pedido`/`Cotizacion`) para no duplicarlos (ver 038-produccion-ordenes-trabajo.md).
 */
class OrdenTrabajoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $documento = $this->documentable;
        $esPedido = $documento instanceof Pedido;

        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'folio_formateado' => $this->folioFormateado(),
            'estado' => $this->estado->value,
            'observaciones' => $this->observaciones,
            'imagen_url' => $this->imagen_ruta !== null
                ? url('/api/v1/ordenes-trabajo/'.$this->id.'/imagen')
                : null,
            'documentable_type' => $this->documentableAlias(),
            'documentable_id' => $this->documentable_id,
            'documento_etiqueta' => $this->documentoEtiqueta(),
            'cliente_nombre' => $esPedido ? $documento->cliente_nombre : $documento->cliente?->razon_social,
            'cliente_telefono' => $esPedido ? $documento->cliente_telefono : $documento->cliente?->telefono,
            'producto' => $documento->relationLoaded('lineas')
                ? $documento->lineas->pluck('descripcion')->implode(', ')
                : null,
            'saldo_pendiente' => $documento->saldoPendiente(),
            'envio' => new EnvioResource($this->whenLoaded('envio')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
