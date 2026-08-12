<?php

namespace App\Http\Resources;

use App\Models\Emisor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Emisor
 */
class EmisorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Los logos viajan como banderas, no como contenido: el emisor se consulta desde varias
     * pantallas para saber si está completo, y arrastrar dos imágenes en base64 en cada una de
     * esas respuestas costaría megabytes por un dato que casi nunca se mira. Quien necesita verlos
     * los pide a `GET /emisor/logo/{tipo}`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'nombre' => $this->nombre,
            'rfc' => $this->rfc,
            'regimen_fiscal' => $this->regimen_fiscal,
            'domicilio' => $this->domicilio,
            'correo' => $this->correo,
            'telefono' => $this->telefono,
            'tiene_logo' => filled($this->logo_ruta),
            'tiene_logo_marca' => filled($this->logo_marca_ruta),
            'esta_completo' => $this->estaCompleto(),
        ];
    }
}
