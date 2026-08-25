<?php

namespace App\Http\Resources;

use App\Services\QrTimbreFiscal;
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
            // Vigente, no congelado: a diferencia del descuento, el precio de un artículo no
            // necesita explicar de dónde salió (ver 033-precio-distribuidor.md).
            'cliente_es_distribuidor' => $this->whenLoaded('cliente', fn () => (bool) $this->cliente->es_distribuidor),
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
            'ajuste_al_peso' => (float) $this->ajuste_al_peso,
            'total' => (float) $this->total,
            'total_pagado' => (float) $this->totalPagado(),
            'saldo_pendiente' => $this->saldoPendiente(),
            'entregado_en' => $this->entregado_en,
            'factura_id' => $this->factura_id,
            'factura_estado' => $this->whenLoaded('factura', fn () => $this->factura?->estado->value),
            // La regla de borrado y la de caducidad son la misma, evaluada en el servidor: el
            // frontend pinta el botón y el aviso sin reimplementarla (ver 008-cotizaciones.md).
            'puede_eliminarse' => $this->puedeEliminarse(),
            'caduca_el' => $this->caducaEl(),
            'lineas' => CotizacionLineaResource::collection($this->whenLoaded('lineas')),
            'pagos' => CotizacionPagoResource::collection($this->whenLoaded('pagos')),
            // Orden de Trabajo de Producción, si esta cotización ya tiene una (ver 038).
            'orden_trabajo_id' => $this->whenLoaded('ordenTrabajo', fn () => $this->ordenTrabajo?->id),
            'orden_trabajo_estado' => $this->whenLoaded('ordenTrabajo', fn () => $this->ordenTrabajo?->estado->value),
            // Envío directo a domicilio (distribuidor, sin Orden de Trabajo — ver 041).
            'envio' => $this->whenLoaded('envio', fn () => $this->envio !== null ? new EnvioResource($this->envio) : null),
            // QR de entrega, igual que Pedido (ver 038). Solo en el detalle, mismo criterio que
            // PedidoResource: dibujar el QR de un listado de quince cotizaciones es trabajo de más.
            $this->mergeWhen($this->relationLoaded('lineas'), fn () => [
                'qr_entrega' => app(QrTimbreFiscal::class)->imagenBase64($this->urlEntrega()),
                'url_entrega' => $this->urlEntrega(),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
