<?php

namespace App\Http\Resources;

use App\Services\MensajePedidoService;
use App\Services\QrTimbreFiscal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PedidoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'numero_ticket' => $this->numeroTicket(),
            'folio_formateado' => $this->folioFormateado(),
            'estado' => $this->estado->value,
            'cliente_nombre' => $this->cliente_nombre,
            'cliente_telefono' => $this->cliente_telefono,
            'cliente_correo' => $this->cliente_correo,
            'descuento_global_tipo' => $this->descuento_global_tipo?->value,
            'descuento_global_valor' => $this->descuento_global_valor !== null ? (float) $this->descuento_global_valor : null,
            'subtotal' => (float) $this->subtotal,
            'total_descuento' => (float) $this->total_descuento,
            'total_iva_16' => (float) $this->total_iva_16,
            'total_iva_0' => (float) $this->total_iva_0,
            'total_exento' => (float) $this->total_exento,
            'ajuste_al_peso' => (float) $this->ajuste_al_peso,
            'total' => (float) $this->total,
            'total_pagado' => $this->totalPagado(),
            'saldo_pendiente' => $this->saldoPendiente(),
            'entregado_en' => $this->entregado_en,
            'puede_eliminarse' => $this->puedeEliminarse(),
            'es_editable' => $this->estado->esEditable(),
            'factura_id' => $this->factura_id,
            'factura_estado' => $this->whenLoaded('factura', fn () => $this->factura?->estado->value),
            // Señal para el listado: alguien intentó facturar y no pudo. Ese cliente ya se fue y no
            // va a insistir, así que es la única forma de que el usuario se entere.
            'autofactura_error' => $this->autofactura_error,
            'autofactura_url' => $this->urlAutofactura(),
            'autofactura_vence_el' => $this->autofactura_token !== null ? $this->autofacturaVenceEl() : null,
            'autofactura_no_disponible' => $this->motivoAutofacturaNoDisponible(),
            'lineas' => PedidoLineaResource::collection($this->whenLoaded('lineas')),
            'pagos' => PedidoPagoResource::collection($this->whenLoaded('pagos')),
            // Orden de Trabajo de Producción, si este pedido ya tiene una (ver 038).
            'orden_trabajo_id' => $this->whenLoaded('ordenTrabajo', fn () => $this->ordenTrabajo?->id),
            'orden_trabajo_estado' => $this->whenLoaded('ordenTrabajo', fn () => $this->ordenTrabajo?->estado->value),
            // Solo en el detalle: los mensajes ya resueltos y el QR dibujado son trabajo que no
            // tiene sentido repetir por cada fila de un listado de quince pedidos.
            $this->mergeWhen($this->relationLoaded('lineas'), fn () => [
                'mensaje_compartible' => app(MensajePedidoService::class)->delTicket($this->resource),
                'mensaje_listo' => app(MensajePedidoService::class)->deListo($this->resource),
                'qr_entrega' => app(QrTimbreFiscal::class)->imagenBase64($this->urlEntrega()),
                'url_entrega' => $this->urlEntrega(),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
