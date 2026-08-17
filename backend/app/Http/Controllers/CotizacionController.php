<?php

namespace App\Http\Controllers;

use App\Enums\EstadoCotizacion;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimiento;
use App\Http\Requests\Cotizaciones\CotizacionPagoRequest;
use App\Http\Requests\Cotizaciones\EnviarCotizacionRequest;
use App\Http\Requests\Cotizaciones\StoreCotizacionRequest;
use App\Http\Requests\Cotizaciones\UpdateCotizacionRequest;
use App\Http\Resources\CotizacionResource;
use App\Models\Cotizacion;
use App\Models\CotizacionPago;
use App\Models\DatoBancario;
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

class CotizacionController extends Controller
{
    /**
     * Zona horaria del negocio (mono-usuario/mono-empresa, ver 008-cotizaciones.md): los atajos
     * "Hoy"/"Esta semana"/"Este mes" del listado representan el día calendario en esta zona, no
     * en UTC (zona de almacenamiento de `created_at`).
     */
    private const ZONA_HORARIA_NEGOCIO = 'America/Mexico_City';

    public function __construct(
        private readonly EnvioDocumentoService $envio,
        private readonly TesoreriaService $tesoreria,
        private readonly InventarioService $inventario,
    ) {}

    /**
     * Display a listing of the resource. Filtros por columna combinables (cliente, RFC, folio,
     * estado) más rango de fecha (ver 008-cotizaciones.md).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $cotizaciones = $request->user()->cotizaciones()
            ->with('cliente')
            // `puede_eliminarse` del Resource necesita saber si tiene pagos: contarlos en la misma
            // consulta evita una por fila del listado.
            ->withCount('pagos')
            ->when($request->string('cliente')->trim()->isNotEmpty(), function ($query) use ($request) {
                $busqueda = '%'.$request->string('cliente')->trim().'%';
                $query->whereHas('cliente', fn ($q) => $q->where('razon_social', 'like', $busqueda));
            })
            ->when($request->string('rfc')->trim()->isNotEmpty(), function ($query) use ($request) {
                $busqueda = '%'.$request->string('rfc')->trim().'%';
                $query->whereHas('cliente', fn ($q) => $q->where('rfc', 'like', $busqueda));
            })
            ->when($request->string('folio')->trim()->isNotEmpty(), fn ($query) => $query->where('folio', 'like', '%'.$request->string('folio')->trim().'%'))
            ->when($request->string('estado')->trim()->isNotEmpty(), fn ($query) => $query->where('estado', (string) $request->string('estado')))
            ->when($request->string('fecha_desde')->trim()->isNotEmpty(), fn ($query) => $query->where('created_at', '>=', Carbon::parse((string) $request->string('fecha_desde'), self::ZONA_HORARIA_NEGOCIO)->startOfDay()->utc()))
            ->when($request->string('fecha_hasta')->trim()->isNotEmpty(), fn ($query) => $query->where('created_at', '<=', Carbon::parse((string) $request->string('fecha_hasta'), self::ZONA_HORARIA_NEGOCIO)->endOfDay()->utc()))
            ->orderByDesc('id')
            ->paginate(15);

        return CotizacionResource::collection($cotizaciones);
    }

    public function store(StoreCotizacionRequest $request): CotizacionResource
    {
        $datos = $request->validated();
        $calculo = $this->calcularYValidarTotal($datos);

        $cotizacion = DB::transaction(function () use ($request, $datos, $calculo) {
            $siguienteFolio = ((int) Cotizacion::where('user_id', $request->user()->id)->max('folio')) + 1;

            $cotizacion = $request->user()->cotizaciones()->create([
                'cliente_id' => $datos['cliente_id'],
                'descuento_cliente_porcentaje' => $this->descuentoVigenteDelCliente($request, (int) $datos['cliente_id']),
                'folio' => $siguienteFolio,
                'estado' => EstadoCotizacion::Borrador->value,
                'descuento_global_tipo' => $datos['descuento_global_tipo'] ?? null,
                'descuento_global_valor' => $datos['descuento_global_valor'] ?? null,
                'subtotal' => $calculo['subtotal'],
                'total_descuento' => $calculo['total_descuento'],
                'total_iva_16' => $calculo['total_iva_16'],
                'total_iva_0' => $calculo['total_iva_0'],
                'total_exento' => $calculo['total_exento'],
                'total' => $calculo['total'],
            ]);

            $this->congelarDatosBancarios($cotizacion);

            $this->guardarLineas($cotizacion, $datos['lineas'], $calculo['lineas']);

            return $cotizacion;
        });

        return new CotizacionResource($cotizacion->load(['cliente', 'lineas.articulo']));
    }

    public function show(Request $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        return new CotizacionResource($cotizacion->load(['cliente', 'lineas.articulo', 'pagos.cuenta', 'factura']));
    }

    /**
     * Solo permitida en borrador/enviada; si estaba enviada, la regresa a borrador (ver
     * 008-cotizaciones.md, supuesto #18).
     */
    public function update(UpdateCotizacionRequest $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);
        abort_unless($cotizacion->estado->esEditable(), 422, 'Solo se puede editar una cotización en borrador o enviada.');

        $datos = $request->validated();
        $calculo = $this->calcularYValidarTotal($datos);

        // La copia congelada solo se reescribe si se cambió de cliente: eso es lo que sostiene que
        // una cotización guardada no se mueva sola cuando la ficha del cliente cambia después, sin
        // romper el reemplazo del descuento de las líneas al elegir otro cliente (ver
        // 015-descuento-permanente-cliente.md).
        $descuentoCliente = (int) $datos['cliente_id'] === $cotizacion->cliente_id
            ? (float) $cotizacion->descuento_cliente_porcentaje
            : $this->descuentoVigenteDelCliente($request, (int) $datos['cliente_id']);

        DB::transaction(function () use ($cotizacion, $datos, $calculo, $descuentoCliente) {
            $cotizacion->update([
                'cliente_id' => $datos['cliente_id'],
                'descuento_cliente_porcentaje' => $descuentoCliente,
                'estado' => EstadoCotizacion::Borrador->value,
                'descuento_global_tipo' => $datos['descuento_global_tipo'] ?? null,
                'descuento_global_valor' => $datos['descuento_global_valor'] ?? null,
                'subtotal' => $calculo['subtotal'],
                'total_descuento' => $calculo['total_descuento'],
                'total_iva_16' => $calculo['total_iva_16'],
                'total_iva_0' => $calculo['total_iva_0'],
                'total_exento' => $calculo['total_exento'],
                'total' => $calculo['total'],
            ]);

            $cotizacion->lineas()->delete();
            $this->guardarLineas($cotizacion, $datos['lineas'], $calculo['lineas']);
        });

        return new CotizacionResource($cotizacion->fresh(['cliente', 'lineas.articulo']));
    }

    /**
     * Borrado físico (se lleva las líneas por FK en cascada) de una cotización que el cliente
     * nunca aprobó. Ver 008-cotizaciones.md, supuestos #32 y #33.
     */
    public function destroy(Request $request, Cotizacion $cotizacion): Response
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);
        abort_unless($cotizacion->estado->esEditable(), 422, 'Solo se puede eliminar una cotización en borrador o enviada.');
        abort_if($cotizacion->factura_id !== null, 422, 'No se puede eliminar una cotización que ya generó una factura.');
        abort_if($cotizacion->tienePagos(), 422, 'Elimina primero los pagos de la cotización: cada uno tiene un movimiento en Tesorería.');

        $cotizacion->delete();

        return response()->noContent();
    }

    /**
     * Envía la cotización por correo o WhatsApp; dispara la transición borrador → enviada.
     */
    public function enviar(EnviarCotizacionRequest $request, Cotizacion $cotizacion): JsonResponse
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        $this->envio->enviarPorCorreo($cotizacion, $request->validated('destinatarios'));

        $this->marcarComoEnviada($cotizacion);

        return response()->json(['enviado' => true]);
    }

    /**
     * Cierra el envío por WhatsApp, que ocurre fuera del servidor: el frontend descarga el PDF y lo
     * comparte con el menú del propio aparato, así que sin este aviso la cotización se quedaría en
     * borrador para siempre (ver 029-pwa-mostrador.md).
     *
     * Sobre una cotización que ya está enviada, pagada o entregada no hace nada y responde igual:
     * volver a compartirle la misma cotización a un cliente es normal y no tiene por qué fallar.
     */
    public function marcarEnviada(Request $request, Cotizacion $cotizacion): JsonResponse
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        $this->marcarComoEnviada($cotizacion);

        return response()->json(['enviado' => true]);
    }

    public function pdf(Request $request, Cotizacion $cotizacion): Response
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        return $this->envio->streamPdf($cotizacion);
    }

    /**
     * Registra un pago (anticipo, saldo o pago total); si la suma acumulada alcanza o supera el
     * total, la cotización pasa a `pagada` (ver 008-cotizaciones.md, supuesto #9).
     *
     * El pago es la única fuente de movimientos automáticos de Tesorería: genera de inmediato un
     * ingreso en la cuenta elegida, dentro de la misma transacción (ver 010-tesoreria.md). Crear la
     * cotización o timbrar su factura no generan ningún movimiento.
     */
    public function pagos(CotizacionPagoRequest $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        $datos = $request->validated();
        // Solo `anticipo` acepta el monto libre enviado; `saldo`/`pago_total` siempre se
        // autocalculan como el saldo pendiente, ignorando cualquier valor enviado (ver
        // 008-cotizaciones.md).
        $monto = $datos['tipo'] === 'anticipo'
            ? (float) $datos['monto']
            : max(0, (float) $cotizacion->total - $cotizacion->totalPagado());

        abort_if($monto <= 0, 422, 'No hay saldo pendiente por registrar.');

        DB::transaction(function () use ($request, $cotizacion, $datos, $monto) {
            $pago = $cotizacion->pagos()->create([
                'tipo' => $datos['tipo'],
                'fecha_pago' => $datos['fecha_pago'],
                'monto' => $monto,
                'cuenta_id' => $datos['cuenta_id'],
            ]);

            $this->tesoreria->registrarDesdeDocumento(
                $request->user(),
                $pago,
                (int) $datos['cuenta_id'],
                TipoMovimiento::Ingreso,
                $monto,
                (string) $datos['fecha_pago'],
                $pago->setRelation('cotizacion', $cotizacion)->conceptoMovimiento(),
            );

            if ($cotizacion->fresh()->totalPagado() >= (float) $cotizacion->total) {
                $cotizacion->update(['estado' => EstadoCotizacion::Pagada->value]);
            }
        });

        return new CotizacionResource($cotizacion->fresh(['cliente', 'lineas.articulo', 'pagos.cuenta']));
    }

    /**
     * Elimina el pago más reciente de la cotización (criterio LIFO), única vía de corrección de un
     * pago mal capturado — 008 no expone edición de pagos (ver 010-tesoreria.md).
     *
     * El criterio LIFO mantiene coherente el historial: como el monto de `saldo`/`pago_total` se
     * autocalcula a partir de los pagos previos, eliminar un pago intermedio dejaría a los
     * posteriores con montos que ya no corresponden al saldo pendiente que tenían al registrarse.
     */
    public function eliminarPago(Request $request, Cotizacion $cotizacion, CotizacionPago $pago): Response
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);
        abort_unless($pago->cotizacion_id === $cotizacion->id, 404);

        abort_if(
            $cotizacion->estado === EstadoCotizacion::ProductoEntregado,
            422,
            'No se pueden eliminar pagos de una cotización con producto entregado.'
        );

        $masReciente = $cotizacion->pagos()->orderByDesc('created_at')->orderByDesc('id')->first();

        abort_unless(
            $masReciente !== null && $masReciente->id === $pago->id,
            422,
            'Solo se puede eliminar el pago más reciente de la cotización.'
        );

        DB::transaction(function () use ($cotizacion, $pago) {
            // Revierte el movimiento en Tesorería y recalcula el saldo de la cuenta afectada.
            $movimiento = $pago->movimiento;
            if ($movimiento !== null) {
                $this->tesoreria->eliminar($movimiento);
            }

            $pago->delete();

            // Revierte la transición automática de 008: si ya no alcanza el total, la cotización
            // deja de estar pagada.
            if ($cotizacion->estado === EstadoCotizacion::Pagada
                && $cotizacion->fresh()->totalPagado() < (float) $cotizacion->total) {
                $cotizacion->update(['estado' => EstadoCotizacion::Enviada->value]);
            }
        });

        return response()->noContent();
    }

    /**
     * Marca la cotización como entregada; solo alcanzable desde `pagada`.
     */
    public function entregar(Request $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);
        abort_unless($cotizacion->estado === EstadoCotizacion::Pagada, 422, 'Solo se puede marcar como entregada una cotización pagada.');

        DB::transaction(function () use ($cotizacion) {
            // El estado se relee bloqueado dentro de la transacción: un doble clic no descuenta la
            // mercancía dos veces (ver 017-inventario.md).
            $bloqueada = Cotizacion::lockForUpdate()->find($cotizacion->id);

            if ($bloqueada === null || $bloqueada->estado !== EstadoCotizacion::Pagada) {
                return;
            }

            // La cotización entregada es el momento en que la mercancía sale físicamente, así que
            // es aquí donde baja el inventario — y si hay una factura vinculada a esta cotización,
            // esa factura no descuenta nada.
            $this->inventario->salidaPorDocumento(
                $bloqueada->lineas()->get(),
                MotivoMovimientoInventario::VentaCotizacion,
                $bloqueada,
            );

            $bloqueada->update(['estado' => EstadoCotizacion::ProductoEntregado->value]);
        });

        return new CotizacionResource($cotizacion->fresh(['cliente', 'lineas.articulo', 'pagos.cuenta']));
    }

    /**
     * Crea una copia nueva (folio propio, borrador, sin factura ni pagos asociados) — ver
     * 008-cotizaciones.md, supuesto #12.
     */
    public function duplicar(Request $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        $cotizacion->load('lineas');

        $copia = DB::transaction(function () use ($request, $cotizacion) {
            $siguienteFolio = ((int) Cotizacion::where('user_id', $request->user()->id)->max('folio')) + 1;

            $copia = $request->user()->cotizaciones()->create([
                'cliente_id' => $cotizacion->cliente_id,
                // Se copia el valor congelado del original, no el vigente del cliente: la copia
                // nace con las mismas líneas y los mismos descuentos, así que leer el vigente
                // dejaría el renglón informativo diciendo una cosa y las líneas mostrando otra.
                'descuento_cliente_porcentaje' => $cotizacion->descuento_cliente_porcentaje,
                'folio' => $siguienteFolio,
                'estado' => EstadoCotizacion::Borrador->value,
                'descuento_global_tipo' => $cotizacion->descuento_global_tipo?->value,
                'descuento_global_valor' => $cotizacion->descuento_global_valor,
                'subtotal' => $cotizacion->subtotal,
                'total_descuento' => $cotizacion->total_descuento,
                'total_iva_16' => $cotizacion->total_iva_16,
                'total_iva_0' => $cotizacion->total_iva_0,
                'total_exento' => $cotizacion->total_exento,
                'total' => $cotizacion->total,
            ]);

            // Foto nueva, no la del original: una copia es una cotización que sale hoy, con el
            // folio de hoy, y debe llevar los datos con los que hoy se cobra. Es distinto del
            // descuento del cliente de arriba, que sí se copia congelado porque tiene que cuadrar
            // con las líneas que se copiaron junto a él.
            $this->congelarDatosBancarios($copia);

            foreach ($cotizacion->lineas as $linea) {
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

        return new CotizacionResource($copia->load(['cliente', 'lineas.articulo']));
    }

    /**
     * Guarda dentro de la cotización la foto de los datos bancarios vigentes
     * (ver 026-datos-bancarios-cotizacion.md).
     *
     * Se toma una sola vez, al crear, y nunca se vuelve a tomar: ni al editar el borrador, ni al
     * enviar, ni al reimprimir. El PDF se regenera cada vez que se abre, y sin esta copia cambiar
     * de banco reescribiría en silencio documentos que el cliente ya tiene en su correo.
     *
     * Es asignación directa y no parte del `create()` porque `datos_bancarios` está fuera de
     * `#[Fillable]`: no es un dato que mande el cliente HTTP.
     */
    private function congelarDatosBancarios(Cotizacion $cotizacion): void
    {
        $cotizacion->datos_bancarios = DatoBancario::fotoParaCotizacion();
        $cotizacion->save();
    }

    /**
     * `borrador` → `enviada`, la única transición que dispara haber mandado la cotización. Los
     * demás estados no retroceden: una cotización pagada que se le vuelve a mandar al cliente sigue
     * pagada.
     */
    private function marcarComoEnviada(Cotizacion $cotizacion): void
    {
        if ($cotizacion->estado === EstadoCotizacion::Borrador) {
            $cotizacion->update(['estado' => EstadoCotizacion::Enviada->value]);
        }
    }

    /**
     * Descuento permanente que tiene hoy la ficha del cliente, para congelarlo en la cotización
     * (ver 015-descuento-permanente-cliente.md). El cliente ya viene validado como propio del
     * usuario por el Form Request; el scope aquí es defensa en profundidad, no la validación.
     */
    private function descuentoVigenteDelCliente(Request $request, int $clienteId): float
    {
        $cliente = $request->user()->clientes()->find($clienteId);

        return (float) ($cliente?->descuento_permanente ?? 0);
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
    private function guardarLineas(Cotizacion $cotizacion, array $lineas, array $lineasCalculadas): void
    {
        foreach ($lineas as $i => $linea) {
            $cotizacion->lineas()->create([
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
