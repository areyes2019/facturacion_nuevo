<?php

namespace App\Http\Resources;

use App\Models\CotizacionPago;
use App\Models\OrdenCompra;
use App\Models\PedidoPago;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoResource extends JsonResource
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
            'cuenta_id' => $this->cuenta_id,
            'cuenta_nombre' => $this->whenLoaded('cuenta', fn () => $this->cuenta->nombre),
            'tipo' => $this->tipo->value,
            'tipo_texto' => $this->tipo->texto(),
            'monto' => (float) $this->monto,
            // Cuánto suma o resta al saldo de la cuenta: el frontend lo usa para mostrar el monto
            // con signo y color según su efecto, sin tener que replicar la regla de cada tipo.
            'efecto_en_saldo' => $this->efectoEnSaldo(),
            'fecha' => $this->fecha->toDateString(),
            'concepto' => $this->concepto,
            'es_automatico' => $this->es_automatico,
            'transferencia_id' => $this->transferencia_id,
            'documento_origen' => $this->documentoOrigen(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Documento que originó el movimiento, en la forma mínima que el frontend necesita para
     * enlazarlo: un `CotizacionPago` o un `PedidoPago` (ingreso, 010) o una `OrdenCompra` pagada
     * (egreso, 012). Futuros módulos se agregan aquí sin tocar el modelo ni la migración.
     *
     * Para los dos orígenes de venta (Cotización y Pedido) se agrega además la utilidad de esa
     * venta, calculada sobre el documento completo (ver 010-tesoreria.md, "Utilidad de venta en
     * movimientos automáticos"): `null` cuando el documento no tiene costo capturado en sus líneas
     * (creado antes de que existiera `costo_unitario`), y `utilidad_parcial` cuando alguna línea sin
     * artículo (línea libre, solo en Pedido) quedó fuera de la suma.
     *
     * @return array{tipo: string, etiqueta: string, ruta: string, id: int, utilidad?: float|null, utilidad_parcial?: bool}|null
     */
    private function documentoOrigen(): ?array
    {
        $documento = $this->documentable;

        if ($documento instanceof CotizacionPago) {
            $cotizacion = $documento->cotizacion;
            $utilidad = $cotizacion->utilidadVenta();

            return [
                'tipo' => 'cotizacion',
                'etiqueta' => 'COT-'.str_pad((string) $cotizacion->folio, 5, '0', STR_PAD_LEFT),
                'ruta' => 'cotizaciones-detalle',
                'id' => $cotizacion->id,
                'utilidad' => $utilidad['utilidad'],
                'utilidad_parcial' => $utilidad['utilidad_parcial'],
            ];
        }

        if ($documento instanceof PedidoPago) {
            $pedido = $documento->pedido;
            $utilidad = $pedido->utilidadVenta();

            return [
                'tipo' => 'pedido',
                'etiqueta' => $pedido->folioFormateado(),
                'ruta' => 'pedidos-detalle',
                'id' => $pedido->id,
                'utilidad' => $utilidad['utilidad'],
                'utilidad_parcial' => $utilidad['utilidad_parcial'],
            ];
        }

        if ($documento instanceof OrdenCompra) {
            return [
                'tipo' => 'orden_compra',
                'etiqueta' => $documento->folioFormateado(),
                'ruta' => 'ordenes-compra-detalle',
                'id' => $documento->id,
            ];
        }

        return null;
    }
}
