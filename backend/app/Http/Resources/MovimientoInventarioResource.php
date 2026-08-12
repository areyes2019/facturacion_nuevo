<?php

namespace App\Http\Resources;

use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\OrdenCompra;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un renglón del historial de inventario (ver 017-inventario.md).
 *
 * Incluye el documento origen ya resuelto a folio y ruta, para que la pantalla pueda enlazarlo sin
 * saber nada de la relación polimórfica.
 */
class MovimientoInventarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'articulo_id' => $this->articulo_id,
            'tipo' => $this->tipo->value,
            'tipo_texto' => $this->tipo->texto(),
            'motivo' => $this->motivo->value,
            'motivo_texto' => $this->motivo->texto(),
            'cantidad' => (int) $this->cantidad,
            'existencia_resultante' => (int) $this->existencia_resultante,
            'faltante_resultante' => (int) $this->faltante_resultante,
            'nota' => $this->nota,
            'es_automatico' => $this->es_automatico,
            'documento' => $this->documento(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array{tipo: string, id: int, folio: string}|null
     */
    private function documento(): ?array
    {
        $documento = $this->whenLoaded('documentable');

        if (! $documento instanceof Model) {
            return null;
        }

        return match (true) {
            $documento instanceof OrdenCompra => [
                'tipo' => 'orden_compra',
                'id' => $documento->id,
                'folio' => $documento->folioFormateado(),
            ],
            $documento instanceof Factura => [
                'tipo' => 'factura',
                'id' => $documento->id,
                'folio' => (string) $documento->folio,
            ],
            $documento instanceof Cotizacion => [
                'tipo' => 'cotizacion',
                'id' => $documento->id,
                'folio' => (string) $documento->folio,
            ],
            default => null,
        };
    }
}
