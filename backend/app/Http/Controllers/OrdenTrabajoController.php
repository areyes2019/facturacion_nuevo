<?php

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenTrabajo;
use App\Http\Requests\Produccion\StoreOrdenTrabajoRequest;
use App\Http\Requests\Produccion\UpdateOrdenTrabajoRequest;
use App\Http\Resources\OrdenTrabajoResource;
use App\Models\Cotizacion;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Producción: el tablero de Órdenes de Trabajo (ver 038-produccion-ordenes-trabajo.md).
 */
class OrdenTrabajoController extends Controller
{
    /**
     * Listado del tablero. Sin `estado[]` en la petición, excluye `entregado` — es el tablero de "lo
     * que falta", no un historial; `estado[]=entregado` lo trae explícitamente.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $estados = array_filter(explode(',', (string) $request->query('estado')));
        $estados = $estados !== [] ? $estados : [
            EstadoOrdenTrabajo::Pendiente->value,
            EstadoOrdenTrabajo::EnProduccion->value,
            EstadoOrdenTrabajo::ListoParaEntregar->value,
            EstadoOrdenTrabajo::ADomicilio->value,
        ];

        $ordenes = $request->user()->ordenTrabajos()
            ->with($this->conDocumento())
            ->whereIn('estado', $estados)
            ->orderByDesc('id')
            ->paginate(15);

        return OrdenTrabajoResource::collection($ordenes);
    }

    /**
     * El documento origen debe ser del usuario, tener al menos un pago y no tener ya una orden —
     * las tres cosas las valida `StoreOrdenTrabajoRequest`.
     */
    public function store(StoreOrdenTrabajoRequest $request): OrdenTrabajoResource
    {
        $documento = $request->resolverDocumento();

        $orden = DB::transaction(function () use ($request, $documento) {
            $siguienteFolio = ((int) OrdenTrabajo::where('user_id', $request->user()->id)->max('folio')) + 1;

            return $request->user()->ordenTrabajos()->create([
                'folio' => $siguienteFolio,
                'estado' => EstadoOrdenTrabajo::Pendiente->value,
                'documentable_type' => $documento::class,
                'documentable_id' => $documento->id,
            ]);
        });

        return new OrdenTrabajoResource($orden->load($this->conDocumento()));
    }

    public function show(Request $request, OrdenTrabajo $orden): OrdenTrabajoResource
    {
        abort_unless($orden->user_id === $request->user()->id, 404);

        return new OrdenTrabajoResource($orden->load([...$this->conDocumento(), 'envio']));
    }

    public function update(UpdateOrdenTrabajoRequest $request, OrdenTrabajo $orden): OrdenTrabajoResource
    {
        abort_unless($orden->user_id === $request->user()->id, 404);

        $orden->update(['observaciones' => $request->validated('observaciones')]);

        return new OrdenTrabajoResource($orden->fresh($this->conDocumento()));
    }

    public function iniciarProduccion(Request $request, OrdenTrabajo $orden): OrdenTrabajoResource
    {
        abort_unless($orden->user_id === $request->user()->id, 404);
        abort_unless($orden->estado === EstadoOrdenTrabajo::Pendiente, 422, 'Solo se puede iniciar producción de una orden pendiente.');

        $orden->update(['estado' => EstadoOrdenTrabajo::EnProduccion->value]);

        return new OrdenTrabajoResource($orden->fresh($this->conDocumento()));
    }

    public function marcarListo(Request $request, OrdenTrabajo $orden): OrdenTrabajoResource
    {
        abort_unless($orden->user_id === $request->user()->id, 404);
        abort_unless($orden->estado === EstadoOrdenTrabajo::EnProduccion, 422, 'Solo se puede marcar como lista una orden en producción.');

        $orden->update(['estado' => EstadoOrdenTrabajo::ListoParaEntregar->value]);

        return new OrdenTrabajoResource($orden->fresh($this->conDocumento()));
    }

    /**
     * Cierra el ciclo cuando el repartidor confirma la entrega a domicilio. Distinto del `entregar`
     * de Pedido/Cotizacion: ese cierra la venta al escanear el QR de mostrador, este solo cierra la
     * Orden de Trabajo que ya salió a domicilio.
     */
    public function entregar(Request $request, OrdenTrabajo $orden): OrdenTrabajoResource
    {
        abort_unless($orden->user_id === $request->user()->id, 404);
        abort_unless($orden->estado === EstadoOrdenTrabajo::ADomicilio, 422, 'Solo se puede marcar como entregada una orden a domicilio.');

        $orden->update(['estado' => EstadoOrdenTrabajo::Entregado->value]);

        return new OrdenTrabajoResource($orden->fresh($this->conDocumento()));
    }

    /**
     * Marca como entregada la Orden de Trabajo ligada a un Pedido/Cotización que acaba de cerrarse
     * por el QR de mostrador. No hace nada si el documento no tiene orden o si ya estaba entregada.
     */
    public static function sincronizarEntrega(Pedido|Cotizacion $documento): void
    {
        $orden = $documento->ordenTrabajo;

        if ($orden !== null && $orden->estado !== EstadoOrdenTrabajo::Entregado) {
            $orden->update(['estado' => EstadoOrdenTrabajo::Entregado->value]);
        }
    }

    /**
     * @return array<int, string|\Closure>
     */
    private function conDocumento(): array
    {
        return [
            'documentable' => function ($morphTo) {
                $morphTo->morphWith([
                    Pedido::class => ['lineas'],
                    Cotizacion::class => ['cliente', 'lineas'],
                ]);
            },
        ];
    }
}
