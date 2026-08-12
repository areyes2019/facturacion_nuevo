<?php

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenCompra;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimiento;
use App\Http\Requests\OrdenesCompra\EnviarOrdenCompraRequest;
use App\Http\Requests\OrdenesCompra\PagarOrdenCompraRequest;
use App\Http\Requests\OrdenesCompra\StoreOrdenCompraRequest;
use App\Http\Requests\OrdenesCompra\UpdateOrdenCompraRequest;
use App\Http\Resources\OrdenCompraResource;
use App\Models\OrdenCompra;
use App\Services\EnvioDocumentoService;
use App\Services\FacturaTotalesCalculator;
use App\Services\InventarioService;
use App\Services\TesoreriaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrdenCompraController extends Controller
{
    /**
     * Zona horaria del negocio (mono-usuario/mono-empresa): los atajos "Hoy"/"Esta semana"/"Este
     * mes" del listado representan el día calendario en esta zona, no en UTC (zona de
     * almacenamiento de `created_at`). Mismo criterio que 008/010.
     */
    private const ZONA_HORARIA_NEGOCIO = 'America/Mexico_City';

    /** Relaciones que necesita el detalle completo de una orden. */
    private const RELACIONES_DETALLE = ['proveedor', 'lineas.articulo', 'cuenta'];

    public function __construct(
        private readonly EnvioDocumentoService $envio,
        private readonly TesoreriaService $tesoreria,
        private readonly InventarioService $inventario,
    ) {}

    /**
     * Listado con filtros por columna combinables (proveedor, RFC, folio, estado) más rango de
     * fecha (ver 012-ordenes-compra.md).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $ordenes = $request->user()->ordenesCompra()
            ->with('proveedor')
            ->when($request->string('proveedor')->trim()->isNotEmpty(), function ($query) use ($request) {
                $busqueda = '%'.$request->string('proveedor')->trim().'%';
                $query->whereHas('proveedor', fn ($q) => $q->where('nombre_comercial', 'like', $busqueda));
            })
            ->when($request->string('rfc')->trim()->isNotEmpty(), function ($query) use ($request) {
                $busqueda = '%'.$request->string('rfc')->trim().'%';
                $query->whereHas('proveedor', fn ($q) => $q->where('rfc', 'like', $busqueda));
            })
            ->when($request->string('folio')->trim()->isNotEmpty(), fn ($query) => $query->where('folio', 'like', '%'.$request->string('folio')->trim().'%'))
            ->when($request->string('estado')->trim()->isNotEmpty(), fn ($query) => $query->where('estado', (string) $request->string('estado')))
            ->when($request->string('fecha_desde')->trim()->isNotEmpty(), fn ($query) => $query->where('created_at', '>=', Carbon::parse((string) $request->string('fecha_desde'), self::ZONA_HORARIA_NEGOCIO)->startOfDay()->utc()))
            ->when($request->string('fecha_hasta')->trim()->isNotEmpty(), fn ($query) => $query->where('created_at', '<=', Carbon::parse((string) $request->string('fecha_hasta'), self::ZONA_HORARIA_NEGOCIO)->endOfDay()->utc()))
            ->orderByDesc('id')
            ->paginate(15);

        return OrdenCompraResource::collection($ordenes);
    }

    public function store(StoreOrdenCompraRequest $request): OrdenCompraResource
    {
        $datos = $request->validated();
        $calculo = $this->calcularYValidarTotal($datos);

        $orden = DB::transaction(function () use ($request, $datos, $calculo) {
            $siguienteFolio = ((int) OrdenCompra::where('user_id', $request->user()->id)->max('folio')) + 1;

            $orden = $request->user()->ordenesCompra()->create([
                ...$this->atributosDelDocumento($datos, $calculo),
                'folio' => $siguienteFolio,
                'estado' => EstadoOrdenCompra::Borrador->value,
            ]);

            $this->guardarLineas($orden, $datos['lineas'], $calculo['lineas']);

            return $orden;
        });

        return new OrdenCompraResource($orden->load(self::RELACIONES_DETALLE));
    }

    public function show(Request $request, OrdenCompra $ordenCompra): OrdenCompraResource
    {
        abort_unless($ordenCompra->user_id === $request->user()->id, 404);

        return new OrdenCompraResource($ordenCompra->load(self::RELACIONES_DETALLE));
    }

    /**
     * Libremente editable mientras no esté pagada; si estaba `enviada`, la regresa a `borrador`
     * para obligar a reenviarla al proveedor (ver 012-ordenes-compra.md, supuesto #13).
     */
    public function update(UpdateOrdenCompraRequest $request, OrdenCompra $ordenCompra): OrdenCompraResource
    {
        abort_unless($ordenCompra->user_id === $request->user()->id, 404);
        abort_unless(
            $ordenCompra->estado->esEditable(),
            422,
            'Solo se puede editar una orden de compra en borrador o enviada. Cancela el pago para volver a editarla.'
        );

        $datos = $request->validated();
        $calculo = $this->calcularYValidarTotal($datos);

        DB::transaction(function () use ($ordenCompra, $datos, $calculo) {
            $ordenCompra->update([
                ...$this->atributosDelDocumento($datos, $calculo),
                'estado' => EstadoOrdenCompra::Borrador->value,
            ]);

            $ordenCompra->lineas()->delete();
            $this->guardarLineas($ordenCompra, $datos['lineas'], $calculo['lineas']);
        });

        return new OrdenCompraResource($ordenCompra->fresh(self::RELACIONES_DETALLE));
    }

    /**
     * Solo permitida en borrador; borrado físico (mismo criterio que Factura y Cotización).
     */
    public function destroy(Request $request, OrdenCompra $ordenCompra): Response
    {
        abort_unless($ordenCompra->user_id === $request->user()->id, 404);
        abort_unless(
            $ordenCompra->estado === EstadoOrdenCompra::Borrador,
            422,
            'Solo se puede eliminar una orden de compra en borrador.'
        );

        $ordenCompra->delete();

        return response()->noContent();
    }

    /**
     * Envía la orden al proveedor por correo o WhatsApp; dispara la transición borrador → enviada.
     */
    public function enviar(EnviarOrdenCompraRequest $request, OrdenCompra $ordenCompra): JsonResponse
    {
        abort_unless($ordenCompra->user_id === $request->user()->id, 404);

        if ($request->validated('canal') === 'correo') {
            $this->envio->enviarPorCorreo($ordenCompra, $request->validated('destinatarios'));
        } else {
            $this->envio->enviarPorWhatsApp($ordenCompra, (string) $request->validated('telefono'));
        }

        if ($ordenCompra->estado === EstadoOrdenCompra::Borrador) {
            $ordenCompra->update(['estado' => EstadoOrdenCompra::Enviada->value]);
        }

        return response()->json(['enviado' => true]);
    }

    /**
     * PDF generado al vuelo, sin autenticación de sesión: exclusivo para que Twilio lo descargue al
     * enviar el WhatsApp. Protegido por firma temporal de la URL (`signed`), no por `auth:sanctum`.
     */
    public function pdfPublico(Request $request, OrdenCompra $ordenCompra): Response
    {
        abort_unless($request->hasValidSignature(), 403);

        return $this->envio->streamPdf($ordenCompra);
    }

    public function pdf(Request $request, OrdenCompra $ordenCompra): Response
    {
        abort_unless($ordenCompra->user_id === $request->user()->id, 404);

        return $this->envio->streamPdf($ordenCompra);
    }

    /**
     * Registra el pago de contado de la orden: único, por el total, y solo desde `enviada`.
     *
     * El monto nunca se recibe del cliente — es el `total` de la orden, tomado del servidor. Genera
     * de inmediato un movimiento de **egreso** en Tesorería sobre la cuenta elegida, dentro de la
     * misma transacción; si el egreso dejaría la cuenta en negativo, `TesoreriaService` lanza la
     * excepción de validación y no se persiste nada (ver 012-ordenes-compra.md).
     */
    public function pagar(PagarOrdenCompraRequest $request, OrdenCompra $ordenCompra): OrdenCompraResource
    {
        abort_unless($ordenCompra->user_id === $request->user()->id, 404);
        abort_unless(
            $ordenCompra->estado === EstadoOrdenCompra::Enviada,
            422,
            'Solo se puede pagar una orden de compra enviada.'
        );

        $datos = $request->validated();

        DB::transaction(function () use ($request, $ordenCompra, $datos) {
            $ordenCompra->update([
                'estado' => EstadoOrdenCompra::Pagada->value,
                'cuenta_id' => $datos['cuenta_id'],
                'fecha_pago' => $datos['fecha_pago'],
            ]);

            $this->tesoreria->registrarDesdeDocumento(
                $request->user(),
                $ordenCompra,
                (int) $datos['cuenta_id'],
                TipoMovimiento::Egreso,
                (float) $ordenCompra->total,
                (string) $datos['fecha_pago'],
                $ordenCompra->conceptoMovimiento(),
            );
        });

        return new OrdenCompraResource($ordenCompra->fresh(self::RELACIONES_DETALLE));
    }

    /**
     * Revierte el pago: elimina el movimiento de egreso, recalcula el saldo de la cuenta y regresa
     * la orden a `enviada` (con lo que vuelve a ser editable). Es el equivalente, para el pago
     * único de contado, de la eliminación LIFO del último `CotizacionPago` que definió 010.
     */
    public function cancelarPago(Request $request, OrdenCompra $ordenCompra): OrdenCompraResource
    {
        abort_unless($ordenCompra->user_id === $request->user()->id, 404);
        abort_unless(
            $ordenCompra->estado === EstadoOrdenCompra::Pagada,
            422,
            'Solo se puede cancelar el pago de una orden de compra pagada.'
        );

        DB::transaction(function () use ($ordenCompra) {
            $movimiento = $ordenCompra->movimiento;
            if ($movimiento !== null) {
                $this->tesoreria->eliminar($movimiento);
            }

            $ordenCompra->update([
                'estado' => EstadoOrdenCompra::Enviada->value,
                'cuenta_id' => null,
                'fecha_pago' => null,
            ]);
        });

        return new OrdenCompraResource($ordenCompra->fresh(self::RELACIONES_DETALLE));
    }

    /**
     * Marca la mercancía como recibida y la **suma al inventario** (ver 017-inventario.md, que
     * supera la decisión original de 012 de no llevar existencias).
     *
     * Sigue siendo total (todas las líneas completas), irreversible y una sola vez. La corrección
     * de un faltante o un sobrante se hace después con un ajuste manual.
     */
    public function recibir(Request $request, OrdenCompra $ordenCompra): OrdenCompraResource
    {
        abort_unless($ordenCompra->user_id === $request->user()->id, 404);
        abort_unless(
            $ordenCompra->estado === EstadoOrdenCompra::Pagada,
            422,
            'Solo se puede marcar como recibida una orden de compra pagada.'
        );

        DB::transaction(function () use ($ordenCompra) {
            // El estado se relee bloqueado DENTRO de la transacción: un doble clic o un reintento
            // de red encuentra la orden ya recibida y no vuelve a sumar la mercancía (que además
            // saldaría faltantes que sí existían). El segundo intento no es un error para el
            // usuario: la orden quedó recibida, que es lo que pidió.
            $bloqueada = OrdenCompra::lockForUpdate()->find($ordenCompra->id);

            if ($bloqueada === null || $bloqueada->estado !== EstadoOrdenCompra::Pagada) {
                return;
            }

            $this->inventario->entradaPorDocumento(
                $bloqueada->lineas()->get(),
                MotivoMovimientoInventario::RecepcionOrden,
                $bloqueada,
            );

            $bloqueada->update(['estado' => EstadoOrdenCompra::Recibida->value]);
        });

        return new OrdenCompraResource($ordenCompra->fresh(self::RELACIONES_DETALLE));
    }

    /**
     * Crea una copia nueva (folio propio, borrador, sin pago registrado ni fecha de entrega).
     */
    public function duplicar(Request $request, OrdenCompra $ordenCompra): OrdenCompraResource
    {
        abort_unless($ordenCompra->user_id === $request->user()->id, 404);

        $ordenCompra->load('lineas');

        $copia = DB::transaction(function () use ($request, $ordenCompra) {
            $siguienteFolio = ((int) OrdenCompra::where('user_id', $request->user()->id)->max('folio')) + 1;

            $copia = $request->user()->ordenesCompra()->create([
                'proveedor_id' => $ordenCompra->proveedor_id,
                'folio' => $siguienteFolio,
                'estado' => EstadoOrdenCompra::Borrador->value,
                'observaciones' => $ordenCompra->observaciones,
                'descuento_global_tipo' => $ordenCompra->descuento_global_tipo?->value,
                'descuento_global_valor' => $ordenCompra->descuento_global_valor,
                'subtotal' => $ordenCompra->subtotal,
                'total_descuento' => $ordenCompra->total_descuento,
                'total_iva_16' => $ordenCompra->total_iva_16,
                'total_iva_0' => $ordenCompra->total_iva_0,
                'total_exento' => $ordenCompra->total_exento,
                'total' => $ordenCompra->total,
            ]);

            foreach ($ordenCompra->lineas as $linea) {
                $copia->lineas()->create([
                    'articulo_id' => $linea->articulo_id,
                    'cantidad' => $linea->cantidad,
                    'descripcion' => $linea->descripcion,
                    'modelo' => $linea->modelo,
                    'precio_unitario' => $linea->precio_unitario,
                    'descuento_tipo' => $linea->descuento_tipo?->value,
                    'descuento_valor' => $linea->descuento_valor,
                    'tasa_iva' => $linea->tasa_iva->value,
                    'importe' => $linea->importe,
                    'iva_importe' => $linea->iva_importe,
                ]);
            }

            return $copia;
        });

        return new OrdenCompraResource($copia->load(self::RELACIONES_DETALLE));
    }

    /**
     * Atributos comunes al alta y a la edición (todo menos folio y estado).
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $calculo
     * @return array<string, mixed>
     */
    private function atributosDelDocumento(array $datos, array $calculo): array
    {
        return [
            'proveedor_id' => $datos['proveedor_id'],
            'fecha_entrega_esperada' => $datos['fecha_entrega_esperada'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
            'descuento_global_tipo' => $datos['descuento_global_tipo'] ?? null,
            'descuento_global_valor' => $datos['descuento_global_valor'] ?? null,
            'subtotal' => $calculo['subtotal'],
            'total_descuento' => $calculo['total_descuento'],
            'total_iva_16' => $calculo['total_iva_16'],
            'total_iva_0' => $calculo['total_iva_0'],
            'total_exento' => $calculo['total_exento'],
            'total' => $calculo['total'],
        ];
    }

    /**
     * Mismo algoritmo de dos pasadas que Factura y Cotización, reutilizando `FacturaTotalesCalculator`
     * sin modificarlo (ver 012-ordenes-compra.md, adición técnica 33).
     *
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
    private function guardarLineas(OrdenCompra $orden, array $lineas, array $lineasCalculadas): void
    {
        foreach ($lineas as $i => $linea) {
            $orden->lineas()->create([
                'articulo_id' => $linea['articulo_id'],
                'cantidad' => $linea['cantidad'],
                'descripcion' => $linea['descripcion'],
                'modelo' => $linea['modelo'],
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
