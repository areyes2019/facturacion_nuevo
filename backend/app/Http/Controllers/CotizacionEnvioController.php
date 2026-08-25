<?php

namespace App\Http\Controllers;

use App\Enums\FormaPagoEnvio;
use App\Enums\TarifaEnvio;
use App\Enums\TipoMovimiento;
use App\Http\Requests\EnvioRequest;
use App\Http\Resources\CotizacionResource;
use App\Models\Cotizacion;
use App\Services\ConfiguracionService;
use App\Services\TesoreriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Envío directo a domicilio de una Cotización de cliente distribuidor, sin pasar por Producción
 * (ver 041-envio-domicilio-direccion-y-distribuidor.md). Un distribuidor compra mecanismos que ya
 * existen en inventario, no algo que haya que fabricar, así que no necesita Orden de Trabajo (038).
 */
class CotizacionEnvioController extends Controller
{
    public function __construct(
        private readonly ConfiguracionService $configuracion,
        private readonly TesoreriaService $tesoreria,
    ) {}

    /**
     * Solo disponible con cliente distribuidor y al menos un pago registrado — mismo criterio de
     * pago que ya exige 038 para crear una Orden de Trabajo. Una cotización admite como máximo un
     * envío directo, igual que una Orden de Trabajo admite como máximo uno (índice único de la
     * migración).
     */
    public function store(EnvioRequest $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);
        $cotizacion->loadMissing('cliente');
        abort_unless($cotizacion->cliente?->es_distribuidor, 422, 'Este envío directo solo aplica a cotizaciones de clientes distribuidores.');
        abort_unless($cotizacion->tienePagos(), 422, 'La cotización necesita al menos un pago registrado para generar un envío.');
        abort_if($cotizacion->envio()->exists(), 422, 'Esta cotización ya tiene un envío registrado.');

        $datos = $request->validated();
        $tarifa = TarifaEnvio::from($datos['tarifa']);
        $formaPago = FormaPagoEnvio::from($datos['forma_pago']);
        $monto = $this->configuracion->montoTarifaEnvio($request->user(), $tarifa);

        DB::transaction(function () use ($request, $cotizacion, $datos, $tarifa, $formaPago, $monto) {
            $envio = $cotizacion->envio()->create([
                'nombre_receptor' => $datos['nombre_receptor'],
                'telefono_receptor' => $datos['telefono_receptor'],
                'direccion' => $datos['direccion'],
                'fecha_recepcion' => $datos['fecha_recepcion'],
                'hora_recepcion' => $datos['hora_recepcion'],
                'tarifa' => $tarifa->value,
                'monto' => $monto,
                'forma_pago' => $formaPago->value,
            ]);

            if ($formaPago === FormaPagoEnvio::Prepagado) {
                $this->tesoreria->registrarDesdeDocumento(
                    $request->user(),
                    $envio,
                    (int) $datos['cuenta_id'],
                    TipoMovimiento::Ingreso,
                    $monto,
                    now()->toDateString(),
                    $envio->setRelation('documentable', $cotizacion)->conceptoMovimiento(),
                );
            }
        });

        return new CotizacionResource($cotizacion->fresh(['cliente', 'lineas', 'pagos', 'envio']));
    }

    /**
     * Marca el envío directo como entregado. Independiente por diseño del QR/estado de la
     * Cotización (`entregado_en`/`estado`): uno es "el paquete salió y llegó", el otro es "el
     * documento se cerró en mostrador" — no se sincronizan.
     */
    public function entregar(Request $request, Cotizacion $cotizacion): CotizacionResource
    {
        abort_unless($cotizacion->user_id === $request->user()->id, 404);

        $envio = $cotizacion->envio()->firstOrFail();
        abort_if($envio->entregado_en !== null, 422, 'Este envío ya fue marcado como entregado.');

        $envio->update(['entregado_en' => now()]);

        return new CotizacionResource($cotizacion->fresh(['cliente', 'lineas', 'pagos', 'envio']));
    }
}
