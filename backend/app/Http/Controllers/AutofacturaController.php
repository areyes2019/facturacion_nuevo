<?php

namespace App\Http\Controllers;

use App\Http\Requests\Pedidos\AutofacturaRequest;
use App\Models\Pedido;
use App\Services\AutofacturaService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Portal público de autofacturación (ver 027-venta-mostrador-ticket.md).
 *
 * Las dos únicas rutas del sistema que cualquiera en internet puede llamar sin sesión —de ahí el
 * `throttle` en `routes/api.php`—. Lo que las protege es el token de 64 caracteres del pedido: con
 * el id a la vista, cualquiera podría cambiar el número a mano y facturar la compra de otro.
 *
 * Responden `404` ante un token inexistente, sin distinguirlo de ningún otro caso: decir "ese token
 * no existe pero este otro sí" convertiría el enlace en algo que se puede sondear.
 */
class AutofacturaController extends Controller
{
    public function __construct(private readonly AutofacturaService $autofactura) {}

    /**
     * Lo mínimo para que el cliente sepa qué está facturando, o el motivo por el que el enlace ya
     * no sirve. Nunca expone las líneas ni el teléfono: es una página abierta.
     */
    public function show(string $token): JsonResponse
    {
        $pedido = $this->pedidoDe($token);

        return response()->json([
            'numero_ticket' => $pedido->numeroTicket(),
            'fecha' => $pedido->created_at,
            'total' => (float) $pedido->total,
            'correo_sugerido' => $pedido->cliente_correo,
            'no_disponible' => $pedido->motivoAutofacturaNoDisponible(),
            'vence_el' => $pedido->autofacturaVenceEl(),
        ]);
    }

    /**
     * Crea el cliente fiscal, timbra y le manda la factura por correo.
     *
     * Un timbrado fallido responde `422` con el motivo ya redactado en español y **no consume el
     * enlace**: el cliente corrige y reintenta ahí mismo.
     */
    public function store(AutofacturaRequest $request, string $token): JsonResponse
    {
        $pedido = $this->pedidoDe($token);

        try {
            $factura = $this->autofactura->facturar($pedido, $request->validated());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->autofactura->enviarPorCorreo($factura, (string) $request->validated('correo'));

        return response()->json([
            'timbrada' => true,
            'folio' => $factura->folio,
            'uuid_fiscal' => $factura->uuid_fiscal,
            'correo' => $request->validated('correo'),
        ]);
    }

    private function pedidoDe(string $token): Pedido
    {
        $pedido = Pedido::where('autofactura_token', $token)->first();

        abort_if($pedido === null, 404, 'Este enlace no es válido.');

        return $pedido;
    }
}
