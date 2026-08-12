<?php

namespace App\View\Composers;

use App\Models\Emisor;
use App\Services\SatDescripciones;
use Illuminate\View\View;

/**
 * Inyecta el emisor y el resolutor de descripciones del SAT en la plantilla base de los PDF
 * (ver 019-formato-pdf-documentos.md).
 *
 * Es un composer y no un parámetro de los controladores porque los PDF salen por seis caminos: los
 * tres endpoints autenticados, las dos rutas públicas firmadas desde las que Twilio descarga el
 * adjunto de WhatsApp, y el envío por correo. Pasarlo a mano obligaría a acordarse en todos, y el
 * día que alguien agregue el séptimo el PDF saldría sin encabezado.
 */
class EmisorComposer
{
    public function __construct(private readonly SatDescripciones $sat) {}

    public function compose(View $view): void
    {
        $view->with([
            'emisor' => Emisor::actual(),
            'sat' => $this->sat,
        ]);
    }
}
