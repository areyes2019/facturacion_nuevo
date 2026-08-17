<?php

namespace App\Services;

use App\Enums\ClaveConfiguracion;
use App\Models\Pedido;

/**
 * Los dos textos que el usuario le manda al cliente de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * - **El mensaje del ticket**, que viaja junto a la imagen al compartirla.
 * - **El aviso de "ya está listo"**, que se manda cuando el trabajo lo está.
 *
 * Los dos viven en Configuración, admiten los mismos huecos y se resuelven aquí, en backend, para
 * que el frontend no tenga que conocer la lista. Un hueco que no exista se deja tal cual: el texto
 * es de captura libre y un `{}` mal escrito no debe romper el envío.
 *
 * Están juntos y fuera de `TicketPedidoService` porque son la misma operación —rellenar una
 * plantilla con los datos de un pedido— y ninguna tiene que ver con dibujar una imagen.
 */
class MensajePedidoService
{
    public function __construct(private readonly ConfiguracionService $configuracion) {}

    /** El mensaje que viaja junto a la imagen del ticket, con los huecos ya resueltos. */
    public function delTicket(Pedido $pedido): string
    {
        return $this->resolver(
            $this->configuracion->obtener($pedido->user, ClaveConfiguracion::MensajeTicket),
            $pedido,
        );
    }

    /**
     * El aviso de que el pedido ya se puede recoger, con los huecos ya resueltos.
     *
     * Lo dispara el usuario cuando el trabajo está listo: el sello se elabora fuera del sistema, así
     * que el sistema no puede saberlo. Lo que sí puede es redactarlo.
     */
    public function deListo(Pedido $pedido): string
    {
        return $this->resolver(
            $this->configuracion->obtener($pedido->user, ClaveConfiguracion::MensajeListo),
            $pedido,
        );
    }

    private function resolver(string $plantilla, Pedido $pedido): string
    {
        return strtr($plantilla, [
            '{nombre}' => (string) $pedido->cliente_nombre,
            '{folio}' => $pedido->numeroTicket(),
            '{total}' => $this->dinero((float) $pedido->total),
            '{pagado}' => $this->dinero($pedido->totalPagado()),
            '{saldo}' => $this->dinero($pedido->saldoPendiente()),
        ]);
    }

    private function dinero(float $monto): string
    {
        return '$'.number_format($monto, 2);
    }
}
