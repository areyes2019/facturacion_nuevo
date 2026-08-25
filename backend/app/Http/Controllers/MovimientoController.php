<?php

namespace App\Http\Controllers;

use App\Http\Requests\Movimientos\StoreMovimientoRequest;
use App\Http\Requests\Movimientos\UpdateMovimientoRequest;
use App\Http\Resources\MovimientoResource;
use App\Models\CotizacionPago;
use App\Models\Movimiento;
use App\Models\PedidoPago;
use App\Services\TesoreriaService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MovimientoController extends Controller
{
    public function __construct(private readonly TesoreriaService $tesoreria) {}

    /**
     * UC-06: listado paginado ordenado por fecha descendente, con los 4 filtros combinables entre
     * sí (rango de fecha, cuenta, tipo y concepto). `fecha` es una columna date, así que el rango
     * compara días calendario directamente, sin conversión de zona horaria.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $movimientos = $request->user()->movimientos()
            // El documento origen es polimórfico: se precarga junto con lo que MovimientoResource
            // necesita de él (el folio y las líneas, para la utilidad de venta), en vez de
            // resolverlo fila por fila.
            ->with([
                'cuenta',
                'documentable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    CotizacionPago::class => ['cotizacion.lineas'],
                    PedidoPago::class => ['pedido.lineas'],
                ]),
            ])
            ->when($request->string('fecha_desde')->trim()->isNotEmpty(), fn ($query) => $query->where('fecha', '>=', (string) $request->string('fecha_desde')))
            ->when($request->string('fecha_hasta')->trim()->isNotEmpty(), fn ($query) => $query->where('fecha', '<=', (string) $request->string('fecha_hasta')))
            ->when($request->filled('cuenta_id'), fn ($query) => $query->where('cuenta_id', $request->integer('cuenta_id')))
            ->when($request->string('tipo')->trim()->isNotEmpty(), fn ($query) => $query->where('tipo', (string) $request->string('tipo')))
            ->when($request->string('concepto')->trim()->isNotEmpty(), fn ($query) => $query->where('concepto', 'like', '%'.$request->string('concepto')->trim().'%'))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 15), 100));

        return MovimientoResource::collection($movimientos);
    }

    /**
     * UC-02/UC-03/UC-05: registra un movimiento manual (ingreso, egreso o ajuste) de una sola
     * cuenta y afecta su saldo de inmediato.
     */
    public function store(StoreMovimientoRequest $request): MovimientoResource
    {
        $movimiento = $this->tesoreria->registrarManual($request->user(), $request->validated());

        return new MovimientoResource($movimiento->load('cuenta'));
    }

    public function show(Request $request, Movimiento $movimiento): MovimientoResource
    {
        abort_unless($movimiento->user_id === $request->user()->id, 404);

        return new MovimientoResource($movimiento->load([
            'cuenta',
            'documentable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                CotizacionPago::class => ['cotizacion.lineas'],
                PedidoPago::class => ['pedido.lineas'],
            ]),
        ]));
    }

    /**
     * Solo movimientos manuales: los automáticos se corrigen desde su documento origen (RN-011).
     */
    public function update(UpdateMovimientoRequest $request, Movimiento $movimiento): MovimientoResource
    {
        abort_unless($movimiento->user_id === $request->user()->id, 404);
        $this->abortSiNoEsEditable($movimiento);

        abort_if(
            $movimiento->transferencia_id !== null,
            422,
            'Una transferencia no se puede editar: sus dos movimientos deben cambiar juntos. Elimínala y regístrala de nuevo.'
        );

        $actualizado = $this->tesoreria->actualizarManual($movimiento, $request->validated());

        return new MovimientoResource($actualizado->load('cuenta'));
    }

    /**
     * Solo movimientos manuales. Eliminar cualquiera de las dos filas de una transferencia elimina
     * ambas (ver TesoreriaService).
     */
    public function destroy(Request $request, Movimiento $movimiento): Response
    {
        abort_unless($movimiento->user_id === $request->user()->id, 404);
        $this->abortSiNoEsEditable($movimiento);

        $this->tesoreria->eliminar($movimiento);

        return response()->noContent();
    }

    private function abortSiNoEsEditable(Movimiento $movimiento): void
    {
        abort_if(
            $movimiento->es_automatico,
            422,
            'Un movimiento automático no se edita ni se elimina desde Tesorería: corrígelo desde su documento origen.'
        );
    }
}
