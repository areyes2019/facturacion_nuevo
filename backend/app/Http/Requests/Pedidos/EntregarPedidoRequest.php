<?php

namespace App\Http\Requests\Pedidos;

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Rules\CuentaActivaDelUsuario;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre del pedido al escanear su QR (ver 027-venta-mostrador-ticket.md).
 *
 * **El monto no viaja en la petición.** Lo calcula el backend como el saldo exacto, para que un
 * frontend manipulado no pueda cerrar un pedido cobrando de menos: la entrega no admite quedar
 * debiendo, porque el sello ya se fue.
 *
 * `cuenta_id` es obligatorio cuando hay saldo y está prohibido cuando no lo hay. Prohibido y no
 * simplemente ignorado: recibir una cuenta en una entrega que no cobra nada solo puede significar
 * que quien llama entendió mal qué está pasando, y callarlo dejaría a alguien creyendo que registró
 * un pago que nunca existió.
 */
class EntregarPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Pedido $pedido */
        $pedido = $this->route('pedido');

        // Un pedido ya entregado no valida cuenta: el controlador responde que ya se entregó y no
        // toca nada, así que exigirla aquí convertiría el candado en un error de captura.
        $cobra = $pedido->estado !== EstadoPedido::Entregado && $pedido->saldoPendiente() > 0;

        return [
            'cuenta_id' => $cobra
                ? ['required', 'integer', new CuentaActivaDelUsuario($this->user()->id)]
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cuenta_id.required' => 'Elige la cuenta a la que entra el dinero.',
            'cuenta_id.prohibited' => 'Este pedido no tiene saldo pendiente, así que no hay nada que cobrar.',
        ];
    }
}
