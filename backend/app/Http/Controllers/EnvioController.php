<?php

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenTrabajo;
use App\Enums\FormaPagoEnvio;
use App\Enums\TarifaEnvio;
use App\Enums\TipoMovimiento;
use App\Http\Requests\EnvioRequest;
use App\Http\Resources\OrdenTrabajoResource;
use App\Models\OrdenTrabajo;
use App\Services\ConfiguracionService;
use App\Services\TesoreriaService;
use Illuminate\Support\Facades\DB;

/**
 * Envío a domicilio de una Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md).
 */
class EnvioController extends Controller
{
    public function __construct(
        private readonly ConfiguracionService $configuracion,
        private readonly TesoreriaService $tesoreria,
    ) {}

    /**
     * Solo disponible con la orden en "Listo para entregar" (sección 6 de la historia). Al crearse
     * el envío, la orden pasa a "A domicilio" en el mismo paso.
     *
     * Un envío `prepagado` genera de inmediato su movimiento en Tesorería: ese dinero ya entró a
     * caja cuando el cliente pagó en el mostrador. Un envío `por_cobrar` no toca Tesorería nunca —
     * ese dinero jamás pasa por el negocio.
     */
    public function store(EnvioRequest $request, OrdenTrabajo $orden): OrdenTrabajoResource
    {
        abort_unless($orden->user_id === $request->user()->id, 404);
        abort_unless($orden->estado === EstadoOrdenTrabajo::ListoParaEntregar, 422, 'Solo se puede enviar a domicilio una orden lista para entregar.');

        $datos = $request->validated();
        $tarifa = TarifaEnvio::from($datos['tarifa']);
        $formaPago = FormaPagoEnvio::from($datos['forma_pago']);
        $monto = $this->configuracion->montoTarifaEnvio($request->user(), $tarifa);

        DB::transaction(function () use ($request, $orden, $datos, $tarifa, $formaPago, $monto) {
            $envio = $orden->envio()->create([
                'nombre_receptor' => $datos['nombre_receptor'],
                'telefono_receptor' => $datos['telefono_receptor'],
                'direccion' => $datos['direccion'],
                'fecha_recepcion' => $datos['fecha_recepcion'],
                'hora_recepcion' => $datos['hora_recepcion'],
                'tarifa' => $tarifa->value,
                'monto' => $monto,
                'forma_pago' => $formaPago->value,
            ]);

            $orden->update(['estado' => EstadoOrdenTrabajo::ADomicilio->value]);

            if ($formaPago === FormaPagoEnvio::Prepagado) {
                $this->tesoreria->registrarDesdeDocumento(
                    $request->user(),
                    $envio,
                    (int) $datos['cuenta_id'],
                    TipoMovimiento::Ingreso,
                    $monto,
                    now()->toDateString(),
                    $envio->setRelation('documentable', $orden)->conceptoMovimiento(),
                );
            }
        });

        return new OrdenTrabajoResource($orden->fresh(['envio', 'documentable']));
    }
}
