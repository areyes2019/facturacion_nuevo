<?php

namespace App\Http\Resources;

use App\Models\DatoBancario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatoBancario
 */
class DatoBancarioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * **Nada se enmascara.** El dato existe para que el cliente pueda pagar, y la pantalla que lo
     * consume está detrás del login como todo el sistema; una tarjeta con asteriscos aquí solo
     * impediría revisar lo que se va a imprimir.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre_banco' => $this->nombre_banco,
            'beneficiario' => $this->beneficiario,
            'numero_cuenta' => $this->numero_cuenta,
            'tarjeta' => $this->tarjeta,
            'clabe' => $this->clabe,
            'visible_en_cotizaciones' => $this->visible_en_cotizaciones,
            'orden' => $this->orden,
            // El icono viaja como bandera y versión, no como contenido: la lista se consulta
            // completa y arrastrar cada imagen en base64 costaría por un dato que se pide aparte.
            // La ruta interna no se expone; es un detalle del servidor.
            'tiene_logo' => filled($this->logo_ruta),
            'logo_version' => $this->logoVersion(),
        ];
    }
}
