<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacturaResource extends JsonResource
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
            // El mismo campo que `CotizacionResource` ya publica: sin él, pintar el RFC en el
            // detalle del mostrador costaría una segunda petición (ver 031-mostrador-consulta.md).
            'cliente_rfc' => $this->whenLoaded('cliente', fn () => $this->cliente->rfc),
            'cliente_correo' => $this->whenLoaded('cliente', fn () => $this->cliente->correo_contacto),
            'uso_cfdi' => $this->uso_cfdi,
            'forma_pago' => $this->forma_pago,
            'metodo_pago' => $this->metodo_pago->value,
            'moneda' => $this->moneda,
            'tipo_comprobante' => $this->tipo_comprobante,
            'descuento_global_tipo' => $this->descuento_global_tipo?->value,
            'descuento_global_valor' => $this->descuento_global_valor !== null ? (float) $this->descuento_global_valor : null,
            'subtotal' => (float) $this->subtotal,
            'total_descuento' => (float) $this->total_descuento,
            'total_iva_16' => (float) $this->total_iva_16,
            'total_iva_0' => (float) $this->total_iva_0,
            'total_exento' => (float) $this->total_exento,
            'ajuste_al_peso' => (float) $this->ajuste_al_peso,
            'total' => (float) $this->total,
            'uuid_fiscal' => $this->uuid_fiscal,
            'facturapi_serie' => $this->facturapi_serie,
            'facturapi_folio' => $this->facturapi_folio,
            'no_certificado_sat' => $this->no_certificado_sat,
            'cadena_original_sat' => $this->cadena_original_sat,
            'fecha_timbrado' => $this->fecha_timbrado,
            'error_timbrado' => $this->error_timbrado,
            'motivo_cancelacion' => $this->motivo_cancelacion?->value,
            'factura_sustituta_id' => $this->factura_sustituta_id,
            'fecha_cancelacion' => $this->fecha_cancelacion,
            'estado_cancelacion' => $this->estado_cancelacion?->value,
            'lineas' => FacturaLineaResource::collection($this->whenLoaded('lineas')),
            'complemento_pago' => new ComplementoPagoResource($this->whenLoaded('complementoPago')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
