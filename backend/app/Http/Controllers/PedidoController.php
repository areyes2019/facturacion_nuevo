<?php

namespace App\Http\Controllers;

use App\Enums\EstadoPedido;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoCuenta;
use App\Enums\TipoMovimiento;
use App\Http\Requests\Pedidos\PedidoPagoRequest;
use App\Http\Requests\Pedidos\StorePedidoRequest;
use App\Http\Requests\Pedidos\UpdatePedidoRequest;
use App\Http\Resources\PedidoResource;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Models\PedidoPago;
use App\Models\User;
use App\Services\FacturaTotalesCalculator;
use App\Services\InventarioService;
use App\Services\TesoreriaService;
use App\Services\TicketPedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Venta de mostrador (ver 027-venta-mostrador-ticket.md).
 */
class PedidoController extends Controller
{
    /** Misma zona horaria del negocio que usa el listado de cotizaciones (008). */
    private const ZONA_HORARIA_NEGOCIO = 'America/Mexico_City';

    public function __construct(
        private readonly TesoreriaService $tesoreria,
        private readonly InventarioService $inventario,
        private readonly TicketPedidoService $ticket,
    ) {}

    /**
     * Listado con filtros por columna combinables, en el mismo estilo de 025.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $pedidos = $request->user()->pedidos()
            ->withCount('pagos')
            ->withSum('pagos', 'monto')
            ->when($request->string('cliente')->trim()->isNotEmpty(), fn ($query) => $query->where('cliente_nombre', 'like', '%'.$request->string('cliente')->trim().'%'))
            ->when($request->string('telefono')->trim()->isNotEmpty(), fn ($query) => $query->where('cliente_telefono', 'like', '%'.$request->string('telefono')->trim().'%'))
            ->when($request->string('folio')->trim()->isNotEmpty(), fn ($query) => $query->where('folio', 'like', '%'.$request->string('folio')->trim().'%'))
            ->when($request->string('estado')->trim()->isNotEmpty(), fn ($query) => $query->where('estado', (string) $request->string('estado')))
            ->when($request->string('fecha_desde')->trim()->isNotEmpty(), fn ($query) => $query->where('created_at', '>=', Carbon::parse((string) $request->string('fecha_desde'), self::ZONA_HORARIA_NEGOCIO)->startOfDay()->utc()))
            ->when($request->string('fecha_hasta')->trim()->isNotEmpty(), fn ($query) => $query->where('created_at', '<=', Carbon::parse((string) $request->string('fecha_hasta'), self::ZONA_HORARIA_NEGOCIO)->endOfDay()->utc()))
            ->orderByDesc('id')
            ->paginate(15);

        return PedidoResource::collection($pedidos);
    }

    /**
     * Levanta la venta y **descuenta existencias en el acto**: la mercancía sale del mostrador en
     * la mano del cliente, no cuando se marca nada (ver 017-inventario.md, "una salida nunca
     * bloquea la operación").
     */
    public function store(StorePedidoRequest $request): PedidoResource
    {
        $datos = $request->validated();
        $calculo = $this->calcularYValidarTotal($datos);

        $pedido = DB::transaction(function () use ($request, $datos, $calculo) {
            $siguienteFolio = ((int) Pedido::where('user_id', $request->user()->id)->max('folio')) + 1;

            $pedido = $request->user()->pedidos()->create([
                'folio' => $siguienteFolio,
                'cliente_nombre' => $datos['cliente_nombre'],
                'cliente_telefono' => $datos['cliente_telefono'],
                'cliente_correo' => $datos['cliente_correo'] ?? null,
                'estado' => EstadoPedido::Pendiente->value,
                'descuento_global_tipo' => $datos['descuento_global_tipo'] ?? null,
                'descuento_global_valor' => $datos['descuento_global_valor'] ?? null,
                ...$this->totales($calculo),
            ]);

            $this->guardarLineas($pedido, $datos['lineas'], $calculo['lineas']);

            $this->inventario->salidaPorDocumento(
                $pedido->lineas()->get(),
                MotivoMovimientoInventario::VentaPedido,
                $pedido,
            );

            return $pedido;
        });

        return new PedidoResource($pedido->load(['lineas.articulo', 'pagos.cuenta']));
    }

    public function show(Request $request, Pedido $pedido): PedidoResource
    {
        abort_unless($pedido->user_id === $request->user()->id, 404);

        return new PedidoResource($pedido->load(['lineas.articulo', 'pagos.cuenta', 'factura']));
    }

    /**
     * Reescribe cliente y líneas completas. Solo mientras el ticket no haya salido hacia el cliente:
     * cambiar los importes debajo de una imagen que ya está en el teléfono de alguien convertiría
     * ese ticket en un papel que no coincide con nada.
     */
    public function update(UpdatePedidoRequest $request, Pedido $pedido): PedidoResource
    {
        abort_unless($pedido->user_id === $request->user()->id, 404);
        abort_unless($pedido->estado->esEditable(), 422, 'Solo se puede editar un pedido pendiente o con anticipo.');

        $datos = $request->validated();
        $calculo = $this->calcularYValidarTotal($datos);

        DB::transaction(function () use ($pedido, $datos, $calculo) {
            // Se devuelve lo que descontó el alta antes de aplicar las líneas nuevas: sin esto, un
            // pedido editado tres veces habría descontado tres veces la misma mercancía.
            $this->inventario->entradaPorDocumento(
                $pedido->lineas()->get(),
                MotivoMovimientoInventario::CorreccionPedido,
                $pedido,
            );

            $pedido->update([
                'cliente_nombre' => $datos['cliente_nombre'],
                'cliente_telefono' => $datos['cliente_telefono'],
                'cliente_correo' => $datos['cliente_correo'] ?? null,
                'descuento_global_tipo' => $datos['descuento_global_tipo'] ?? null,
                'descuento_global_valor' => $datos['descuento_global_valor'] ?? null,
                ...$this->totales($calculo),
            ]);

            $pedido->lineas()->delete();
            $this->guardarLineas($pedido, $datos['lineas'], $calculo['lineas']);

            $this->inventario->salidaPorDocumento(
                $pedido->lineas()->get(),
                MotivoMovimientoInventario::VentaPedido,
                $pedido,
            );

            // El total pudo bajar por debajo de lo ya pagado: el estado se rehace desde los pagos.
            $pedido->refresh()->sincronizarEstadoConPagos();
            $pedido->asegurarTokenAutofactura();
        });

        $this->ticket->invalidar($pedido);

        return new PedidoResource($pedido->fresh(['lineas.articulo', 'pagos.cuenta']));
    }

    /**
     * Borrado físico, solo mientras nadie haya pagado nada. Con pagos de por medio hay movimientos
     * de Tesorería colgando y quien sabe revertirlos es el endpoint de pagos.
     */
    public function destroy(Request $request, Pedido $pedido): Response
    {
        abort_unless($pedido->user_id === $request->user()->id, 404);
        abort_if($pedido->factura_id !== null, 422, 'No se puede eliminar un pedido que ya generó una factura.');
        abort_if($pedido->tienePagos(), 422, 'Elimina primero los pagos del pedido: cada uno tiene un movimiento en Tesorería.');
        abort_unless($pedido->estado === EstadoPedido::Pendiente, 422, 'Solo se puede eliminar un pedido sin pagos ni entrega.');

        DB::transaction(function () use ($pedido) {
            $this->inventario->entradaPorDocumento(
                $pedido->lineas()->get(),
                MotivoMovimientoInventario::CorreccionPedido,
                $pedido,
            );

            $this->ticket->invalidar($pedido);
            $pedido->delete();
        });

        return response()->noContent();
    }

    /**
     * El ticket como imagen. Se dibuja aquí si aún no existe, de modo que la primera petición
     * después de un cambio siempre trae el saldo al día.
     */
    public function ticket(Request $request, Pedido $pedido): Response
    {
        abort_unless($pedido->user_id === $request->user()->id, 404);

        return response($this->ticket->contenido($pedido), 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="ticket-'.$pedido->numeroTicket().'.jpg"',
        ]);
    }

    /**
     * Registra un pago. Sin tipo y sin mínimo: el usuario captura el monto que recibió y el estado
     * se deriva de la suma (ver 027-venta-mostrador-ticket.md).
     */
    public function pagos(PedidoPagoRequest $request, Pedido $pedido): PedidoResource
    {
        abort_unless($pedido->user_id === $request->user()->id, 404);

        $datos = $request->validated();

        DB::transaction(function () use ($request, $pedido, $datos) {
            $this->registrarPago(
                $request->user(),
                $pedido,
                (float) $datos['monto'],
                (int) $datos['cuenta_id'],
                (string) $datos['fecha_pago'],
                automatico: false,
            );

            $pedido->refresh()->sincronizarEstadoConPagos();
            $pedido->asegurarTokenAutofactura();
        });

        $this->ticket->invalidar($pedido);

        return new PedidoResource($pedido->fresh(['lineas.articulo', 'pagos.cuenta']));
    }

    /**
     * Elimina un pago y revierte su movimiento de Tesorería.
     *
     * Sin la regla LIFO de 008: allá el monto de "saldo" se autocalcula a partir de los pagos
     * previos y borrar uno intermedio dejaría a los posteriores describiendo un saldo que ya no
     * existía; aquí cada pago lleva su propio monto capturado y ninguno depende de otro. Es además
     * la vía por la que se corrige el cobro automático del escaneo cuando el cliente en realidad
     * pagó por transferencia.
     */
    public function eliminarPago(Request $request, Pedido $pedido, PedidoPago $pago): Response
    {
        abort_unless($pedido->user_id === $request->user()->id, 404);
        abort_unless($pago->pedido_id === $pedido->id, 404);
        abort_if($pedido->factura_id !== null, 422, 'No se pueden eliminar pagos de un pedido ya facturado.');

        DB::transaction(function () use ($pedido, $pago) {
            $movimiento = $pago->movimiento;

            if ($movimiento !== null) {
                $this->tesoreria->eliminar($movimiento);
            }

            $pago->delete();

            $pedido->refresh()->sincronizarEstadoConPagos();
        });

        $this->ticket->invalidar($pedido);

        return response()->noContent();
    }

    /**
     * Destino del QR de la etiqueta: **cobra el saldo y entrega, sin preguntar nada**.
     *
     * El candado es la relectura bloqueada dentro de la transacción: dos escaneos seguidos —o dos
     * personas escaneando a la vez— no pueden cobrar el saldo dos veces ni meter dinero de más a la
     * caja. El segundo escaneo no toca nada y lo dice.
     */
    public function entregar(Request $request, Pedido $pedido): JsonResponse
    {
        abort_unless($pedido->user_id === $request->user()->id, 404);

        $resultado = DB::transaction(function () use ($request, $pedido) {
            $bloqueado = Pedido::lockForUpdate()->find($pedido->id);

            if ($bloqueado === null || $bloqueado->estado === EstadoPedido::Entregado) {
                return [
                    'ya_estaba_entregado' => true,
                    'cobrado' => 0.0,
                    'cuenta_nombre' => null,
                    'aviso' => null,
                ];
            }

            $saldo = $bloqueado->saldoPendiente();
            $cuenta = $saldo > 0 ? $this->cajaDe($request->user()) : null;
            $aviso = null;

            if ($saldo > 0 && $cuenta === null) {
                // Sin cuenta de efectivo no se inventa una, pero tampoco se bloquea la entrega del
                // trabajo: el cliente está enfrente esperando su sello.
                $aviso = 'No hay ninguna cuenta de efectivo activa, así que el saldo no se registró. Captúralo a mano desde el pedido.';
            }

            if ($saldo > 0 && $cuenta !== null) {
                $this->registrarPago(
                    $request->user(),
                    $bloqueado,
                    $saldo,
                    $cuenta->id,
                    now()->toDateString(),
                    automatico: true,
                );
            }

            $bloqueado->update([
                'estado' => EstadoPedido::Entregado->value,
                'entregado_en' => now(),
            ]);

            $bloqueado->refresh()->asegurarTokenAutofactura();

            return [
                'ya_estaba_entregado' => false,
                'cobrado' => $cuenta !== null ? $saldo : 0.0,
                'cuenta_nombre' => $cuenta?->nombre,
                'aviso' => $aviso,
            ];
        });

        if (! $resultado['ya_estaba_entregado']) {
            $this->ticket->invalidar($pedido);
        }

        return response()->json([
            ...$resultado,
            'pedido' => new PedidoResource($pedido->fresh(['lineas.articulo', 'pagos.cuenta', 'factura'])),
        ]);
    }

    /**
     * Revierte una entrega recién hecha, por si se escaneó la etiqueta equivocada.
     *
     * Revierte **las dos cosas**: borra el pago automático con su movimiento de Tesorería —si solo
     * regresara el estado quedarían pesos fantasma en la caja— y devuelve el pedido a la escalera
     * de pagos.
     *
     * La ventana del servidor es más ancha que los 10 segundos del botón a propósito: el botón mide
     * la impaciencia del usuario, este límite evita que un "Deshacer" disparado desde una pestaña
     * olvidada revierta mañana un cobro legítimo.
     */
    public function deshacerEntrega(Request $request, Pedido $pedido): JsonResponse
    {
        abort_unless($pedido->user_id === $request->user()->id, 404);
        abort_unless($pedido->estado === EstadoPedido::Entregado, 422, 'Este pedido no está entregado.');
        abort_if($pedido->factura_id !== null, 422, 'No se puede deshacer la entrega de un pedido ya facturado.');
        abort_if(
            $pedido->entregado_en === null
                || $pedido->entregado_en->lt(now()->subMinutes(Pedido::MINUTOS_PARA_DESHACER_ENTREGA)),
            422,
            'Ya pasó el plazo para deshacer esta entrega. Corrige el pago desde el detalle del pedido.',
        );

        DB::transaction(function () use ($pedido) {
            $automatico = $pedido->pagoAutomatico();

            if ($automatico !== null) {
                $movimiento = $automatico->movimiento;

                if ($movimiento !== null) {
                    $this->tesoreria->eliminar($movimiento);
                }

                $automatico->delete();
            }

            $pedido->update(['entregado_en' => null, 'estado' => EstadoPedido::Pendiente->value]);
            $pedido->refresh()->sincronizarEstadoConPagos();
        });

        $this->ticket->invalidar($pedido);

        return response()->json([
            'deshecho' => true,
            'pedido' => new PedidoResource($pedido->fresh(['lineas.articulo', 'pagos.cuenta'])),
        ]);
    }

    /**
     * Datos del último pedido de ese teléfono, para ofrecer rellenar nombre y correo al capturar
     * uno nuevo. Es una sugerencia, no un autocompletado: quien decide es el usuario.
     */
    public function porTelefono(Request $request): JsonResponse
    {
        $telefono = trim((string) $request->string('telefono'));

        if ($telefono === '') {
            return response()->json(['encontrado' => false]);
        }

        $ultimo = $request->user()->pedidos()
            ->where('cliente_telefono', $telefono)
            ->orderByDesc('id')
            ->first();

        if ($ultimo === null) {
            return response()->json(['encontrado' => false]);
        }

        return response()->json([
            'encontrado' => true,
            'cliente_nombre' => $ultimo->cliente_nombre,
            'cliente_correo' => $ultimo->cliente_correo,
        ]);
    }

    /**
     * La caja del mostrador: la cuenta de efectivo activa más antigua del usuario.
     *
     * Es una regla fija y no un ajuste configurable porque el escaneo no puede detenerse a
     * preguntar. Si el cliente pagó por transferencia, el pago se corrige después borrándolo y
     * recapturándolo con la cuenta correcta.
     */
    private function cajaDe(User $user): ?Cuenta
    {
        return $user->cuentas()
            ->where('tipo', TipoCuenta::Efectivo->value)
            ->where('activa', true)
            ->orderBy('id')
            ->first();
    }

    private function registrarPago(
        User $user,
        Pedido $pedido,
        float $monto,
        int $cuentaId,
        string $fecha,
        bool $automatico,
    ): PedidoPago {
        $pago = $pedido->pagos()->create([
            'fecha_pago' => $fecha,
            'monto' => $monto,
            'cuenta_id' => $cuentaId,
            'automatico' => $automatico,
        ]);

        $this->tesoreria->registrarDesdeDocumento(
            $user,
            $pago,
            $cuentaId,
            TipoMovimiento::Ingreso,
            $monto,
            $fecha,
            $pago->setRelation('pedido', $pedido)->conceptoMovimiento(),
        );

        return $pago;
    }

    /**
     * @param  array<string, mixed>  $calculo
     * @return array<string, float>
     */
    private function totales(array $calculo): array
    {
        return [
            'subtotal' => $calculo['subtotal'],
            'total_descuento' => $calculo['total_descuento'],
            'total_iva_16' => $calculo['total_iva_16'],
            'total_iva_0' => $calculo['total_iva_0'],
            'total_exento' => $calculo['total_exento'],
            'total' => $calculo['total'],
        ];
    }

    /**
     * @param  array{lineas: array<int, array<string, mixed>>, descuento_global_tipo?: ?string, descuento_global_valor?: ?float, total: float}  $datos
     * @return array{
     *     lineas: array<int, array{importe: float, iva_importe: float}>,
     *     subtotal: float,
     *     total_descuento: float,
     *     total_iva_16: float,
     *     total_iva_0: float,
     *     total_exento: float,
     *     total: float,
     * }
     */
    private function calcularYValidarTotal(array $datos): array
    {
        $calculo = FacturaTotalesCalculator::calcular(
            $datos['lineas'],
            $datos['descuento_global_tipo'] ?? null,
            $datos['descuento_global_valor'] ?? null,
        );

        if (abs($calculo['total'] - (float) $datos['total']) > 0.01) {
            throw ValidationException::withMessages([
                'total' => 'El total no coincide con el calculado a partir de las líneas.',
            ]);
        }

        return $calculo;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     * @param  array<int, array{importe: float, iva_importe: float}>  $lineasCalculadas
     */
    private function guardarLineas(Pedido $pedido, array $lineas, array $lineasCalculadas): void
    {
        foreach ($lineas as $i => $linea) {
            $pedido->lineas()->create([
                'articulo_id' => $linea['articulo_id'] ?? null,
                'cantidad' => $linea['cantidad'],
                'descripcion' => $linea['descripcion'],
                'modelo' => $linea['modelo'] ?? null,
                'precio_unitario' => $linea['precio_unitario'],
                'descuento_tipo' => $linea['descuento_tipo'] ?? null,
                'descuento_valor' => $linea['descuento_valor'] ?? null,
                'tasa_iva' => $linea['tasa_iva'],
                'importe' => $lineasCalculadas[$i]['importe'],
                'iva_importe' => $lineasCalculadas[$i]['iva_importe'],
            ]);
        }
    }
}
