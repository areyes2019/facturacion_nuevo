<?php

namespace App\Http\Controllers;

use App\Enums\EstadoCancelacion;
use App\Enums\EstadoComplementoPago;
use App\Enums\EstadoFactura;
use App\Enums\MotivoCancelacion;
use App\Enums\MotivoMovimientoInventario;
use App\Http\Requests\Facturas\CancelarFacturaRequest;
use App\Http\Requests\Facturas\ComplementoPagoRequest;
use App\Http\Requests\Facturas\EnviarCorreoRequest;
use App\Http\Requests\Facturas\StoreFacturaRequest;
use App\Http\Requests\Facturas\UpdateFacturaRequest;
use App\Http\Resources\ComplementoPagoResource;
use App\Http\Resources\FacturaResource;
use App\Mail\FacturaEnviadaMail;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use App\Services\FacturapiService;
use App\Services\FacturaTotalesCalculator;
use App\Services\InventarioService;
use Carbon\Carbon;
use Facturapi\Exceptions\FacturapiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class FacturaController extends Controller
{
    private const ZONA_HORARIA_NEGOCIO = 'America/Mexico_City';

    public function __construct(
        private readonly FacturapiService $facturapi,
        private readonly InventarioService $inventario,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $facturas = $request->user()->facturas()
            ->with('cliente')
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search')->trim().'%';
                $query->where(function ($query) use ($search) {
                    $query->where('folio', 'like', $search)
                        ->orWhere('uuid_fiscal', 'like', $search)
                        ->orWhereHas('cliente', fn ($q) => $q->where('razon_social', 'like', $search));
                });
            })
            ->when($request->string('estado')->trim()->isNotEmpty(), fn ($query) => $query->where('estado', (string) $request->string('estado')))
            // Mismos filtros de fecha que el listado de cotizaciones, con la misma interpretación:
            // la fecha llega como día del negocio y se compara contra `created_at` en UTC. Sin
            // ellos, la lista del mostrador no puede acotarse a 30 días (ver 031-mostrador-consulta.md).
            ->when($request->string('fecha_desde')->trim()->isNotEmpty(), fn ($query) => $query->where('created_at', '>=', Carbon::parse((string) $request->string('fecha_desde'), self::ZONA_HORARIA_NEGOCIO)->startOfDay()->utc()))
            ->when($request->string('fecha_hasta')->trim()->isNotEmpty(), fn ($query) => $query->where('created_at', '<=', Carbon::parse((string) $request->string('fecha_hasta'), self::ZONA_HORARIA_NEGOCIO)->endOfDay()->utc()))
            ->orderByDesc('id')
            ->paginate(15);

        return FacturaResource::collection($facturas);
    }

    /**
     * Crea la factura y de inmediato intenta timbrarla contra facturapi.io.
     */
    public function store(StoreFacturaRequest $request): FacturaResource
    {
        $datos = $request->validated();

        $calculo = $this->calcularYValidarTotal($datos);

        $factura = DB::transaction(function () use ($request, $datos, $calculo) {
            $siguienteFolio = ((int) Factura::where('user_id', $request->user()->id)->max('folio')) + 1;

            $factura = $request->user()->facturas()->create([
                'cliente_id' => $datos['cliente_id'],
                'folio' => $siguienteFolio,
                'estado' => EstadoFactura::Borrador->value,
                'uso_cfdi' => $datos['uso_cfdi'],
                'forma_pago' => $datos['forma_pago'],
                'metodo_pago' => $datos['metodo_pago'],
                'descuento_global_tipo' => $datos['descuento_global_tipo'] ?? null,
                'descuento_global_valor' => $datos['descuento_global_valor'] ?? null,
                'subtotal' => $calculo['subtotal'],
                'total_descuento' => $calculo['total_descuento'],
                'total_iva_16' => $calculo['total_iva_16'],
                'total_iva_0' => $calculo['total_iva_0'],
                'total_exento' => $calculo['total_exento'],
                'ajuste_al_peso' => $calculo['ajuste_al_peso'],
                'total' => $calculo['total'],
            ]);

            $this->guardarLineas($factura, $datos['lineas'], $calculo['lineas']);

            // El vínculo con la cotización se escribe ANTES de timbrar, no después: el timbrado
            // consulta `mueveInventario()` para decidir si descuenta existencias, y con el vínculo
            // aún sin guardar vería una factura de mostrador y descontaría mercancía que la
            // cotización va a descontar otra vez al entregarse (ver 017-inventario.md).
            if (! empty($datos['cotizacion_id'])) {
                Cotizacion::where('id', $datos['cotizacion_id'])
                    ->where('user_id', $request->user()->id)
                    ->update(['factura_id' => $factura->id]);
            }

            return $factura;
        });

        $this->intentarTimbrar($factura);

        return new FacturaResource($factura->load(['cliente', 'lineas.articulo']));
    }

    /**
     * Display the specified resource. Si la cancelación quedó pendiente/en verificación,
     * re-consulta a facturapi.io antes de responder (ver 007-facturacion.md, adición 38).
     */
    public function show(Request $request, Factura $factura): FacturaResource
    {
        abort_unless($factura->user_id === $request->user()->id, 404);

        if ($factura->estado_cancelacion !== null && $factura->estado_cancelacion !== EstadoCancelacion::Accepted) {
            $this->refrescarEstadoCancelacion($factura);
        }

        return new FacturaResource($factura->load(['cliente', 'lineas.articulo', 'complementoPago']));
    }

    /**
     * Update the specified resource in storage. Solo permitida en borrador/pendiente; reintenta
     * el timbrado al guardar.
     */
    public function update(UpdateFacturaRequest $request, Factura $factura): FacturaResource
    {
        abort_unless($factura->user_id === $request->user()->id, 404);
        abort_unless($factura->estado->esEditable(), 422, 'Solo se puede editar una factura en borrador o pendiente.');

        $datos = $request->validated();
        $calculo = $this->calcularYValidarTotal($datos);

        DB::transaction(function () use ($factura, $datos, $calculo) {
            $factura->update([
                'cliente_id' => $datos['cliente_id'],
                'uso_cfdi' => $datos['uso_cfdi'],
                'forma_pago' => $datos['forma_pago'],
                'metodo_pago' => $datos['metodo_pago'],
                'descuento_global_tipo' => $datos['descuento_global_tipo'] ?? null,
                'descuento_global_valor' => $datos['descuento_global_valor'] ?? null,
                'subtotal' => $calculo['subtotal'],
                'total_descuento' => $calculo['total_descuento'],
                'total_iva_16' => $calculo['total_iva_16'],
                'total_iva_0' => $calculo['total_iva_0'],
                'total_exento' => $calculo['total_exento'],
                'ajuste_al_peso' => $calculo['ajuste_al_peso'],
                'total' => $calculo['total'],
                'error_timbrado' => null,
            ]);

            $factura->lineas()->delete();
            $this->guardarLineas($factura, $datos['lineas'], $calculo['lineas']);
        });

        $this->intentarTimbrar($factura);

        return new FacturaResource($factura->load(['cliente', 'lineas.articulo']));
    }

    /**
     * Remove the specified resource from storage. Solo permitida en borrador/pendiente; borrado
     * físico (sin soft delete, ver 007-facturacion.md).
     */
    public function destroy(Request $request, Factura $factura): Response
    {
        abort_unless($factura->user_id === $request->user()->id, 404);
        abort_unless($factura->estado->esEditable(), 422, 'Solo se puede eliminar una factura en borrador o pendiente.');

        $factura->delete();

        return response()->noContent();
    }

    /**
     * Reintenta el timbrado de una factura pendiente, sin recapturar datos.
     */
    public function timbrar(Request $request, Factura $factura): FacturaResource
    {
        abort_unless($factura->user_id === $request->user()->id, 404);
        abort_unless($factura->estado === EstadoFactura::Pendiente, 422, 'Solo se puede reintentar el timbrado de una factura pendiente.');

        $this->intentarTimbrar($factura);

        return new FacturaResource($factura->load(['cliente', 'lineas.articulo']));
    }

    /**
     * Cancela una factura timbrada ante facturapi.io.
     */
    public function cancelar(CancelarFacturaRequest $request, Factura $factura): FacturaResource
    {
        abort_unless($factura->user_id === $request->user()->id, 404);
        abort_unless($factura->estado === EstadoFactura::Timbrada, 422, 'Solo se puede cancelar una factura timbrada.');

        $motivo = MotivoCancelacion::from($request->validated('motivo_cancelacion'));
        $facturaSustitutaId = $request->validated('factura_sustituta_id');
        $facturaSustitutaUuid = $facturaSustitutaId !== null
            ? Factura::findOrFail($facturaSustitutaId)->uuid_fiscal
            : null;

        try {
            $respuesta = $this->facturapi->cancelarFactura($factura, $motivo, $facturaSustitutaUuid);

            $estadoCancelacion = EstadoCancelacion::from($respuesta->cancellation_status ?? 'pending');

            $factura->update([
                'motivo_cancelacion' => $motivo->value,
                'factura_sustituta_id' => $facturaSustitutaId,
                'fecha_cancelacion' => now(),
                'estado_cancelacion' => $estadoCancelacion->value,
                'estado' => $estadoCancelacion === EstadoCancelacion::Accepted
                    ? EstadoFactura::Cancelada->value
                    : $factura->estado->value,
            ]);

            $this->devolverInventario($factura, $estadoCancelacion);
        } catch (FacturapiException $exception) {
            throw ValidationException::withMessages([
                'motivo_cancelacion' => $exception->getMessage(),
            ]);
        }

        return new FacturaResource($factura->fresh(['cliente', 'lineas.articulo']));
    }

    /**
     * Proxy en vivo a facturapi.io: no se guarda ni se lee copia local del XML (ver
     * 007-facturacion.md, "Recuperación de XML/PDF").
     */
    public function xml(Request $request, Factura $factura): Response
    {
        abort_unless($factura->user_id === $request->user()->id, 404);
        abort_unless(in_array($factura->estado, [EstadoFactura::Timbrada, EstadoFactura::Cancelada], true), 404);

        try {
            $xml = $this->facturapi->descargarXml((string) $factura->facturapi_invoice_id);
        } catch (FacturapiException) {
            abort(502, 'No se pudo obtener el XML, intenta de nuevo.');
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="factura-'.$factura->folio.'.xml"',
        ]);
    }

    /**
     * Genera el PDF al vuelo a partir de los datos ya guardados localmente; no llama a
     * facturapi.io ni depende del XML (ver 007-facturacion.md).
     */
    public function pdf(Request $request, Factura $factura): Response
    {
        abort_unless($factura->user_id === $request->user()->id, 404);
        abort_unless(in_array($factura->estado, [EstadoFactura::Timbrada, EstadoFactura::Cancelada], true), 404);

        // `withTrashed` en el artículo: la Clave SAT del PDF sale de ahí y las líneas no guardan
        // copia propia, así que sin esto una factura de un artículo dado de baja perdería esa
        // columna al reimprimirse (ver 019-formato-pdf-documentos.md).
        $factura->load(['cliente', 'lineas.articulo' => fn ($relacion) => $relacion->withTrashed()]);

        $pdf = app('dompdf.wrapper')->loadView('pdf.factura', ['factura' => $factura]);

        return $pdf->stream('factura-'.$factura->folio.'.pdf');
    }

    /**
     * Envía la factura (XML descargado en vivo + PDF generado al vuelo) por correo.
     */
    public function enviarCorreo(EnviarCorreoRequest $request, Factura $factura): JsonResponse
    {
        abort_unless($factura->user_id === $request->user()->id, 404);
        abort_unless($factura->estado === EstadoFactura::Timbrada, 422, 'Solo se puede enviar por correo una factura timbrada.');

        $factura->load(['cliente', 'lineas.articulo' => fn ($relacion) => $relacion->withTrashed()]);

        try {
            $xml = $this->facturapi->descargarXml((string) $factura->facturapi_invoice_id);
        } catch (FacturapiException) {
            abort(502, 'No se pudo obtener el XML para enviar, intenta de nuevo.');
        }

        $pdf = app('dompdf.wrapper')->loadView('pdf.factura', ['factura' => $factura])->output();

        Mail::to($request->validated('destinatarios'))
            ->send(new FacturaEnviadaMail($factura, $pdf, $xml));

        return response()->json(['enviado' => true]);
    }

    /**
     * Crea y timbra el complemento de pago de una factura PPD ya timbrada.
     */
    public function complementoPago(ComplementoPagoRequest $request, Factura $factura): ComplementoPagoResource
    {
        abort_unless($factura->user_id === $request->user()->id, 404);
        abort_unless($factura->estado === EstadoFactura::Timbrada, 422, 'La factura debe estar timbrada.');
        abort_unless($factura->metodo_pago->value === 'PPD', 422, 'Solo las facturas con método de pago PPD admiten complemento de pago.');
        abort_if($factura->complementoPago()->exists(), 422, 'Esta factura ya tiene un complemento de pago registrado.');

        $complemento = $factura->complementoPago()->create([
            ...$request->validated(),
            'estado' => EstadoComplementoPago::Pendiente->value,
        ]);

        try {
            $respuesta = $this->facturapi->timbrarComplementoPago($complemento);

            $complemento->update([
                'estado' => EstadoComplementoPago::Timbrado->value,
                'facturapi_invoice_id' => $respuesta->id ?? null,
                'uuid_fiscal' => $respuesta->uuid ?? null,
                'sello_cfdi' => $respuesta->stamp->signature ?? null,
                'cadena_original_sat' => $respuesta->stamp->complement_string ?? null,
            ]);
        } catch (FacturapiException $exception) {
            $complemento->update([
                'estado' => EstadoComplementoPago::Error->value,
                'error_timbrado' => $exception->getMessage(),
            ]);
        }

        return new ComplementoPagoResource($complemento);
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
     *     ajuste_al_peso: float,
     *     total: float,
     * }
     */
    private function calcularYValidarTotal(array $datos): array
    {
        $calculo = FacturaTotalesCalculator::calcular(
            $datos['lineas'],
            $datos['descuento_global_tipo'] ?? null,
            $datos['descuento_global_valor'] ?? null,
            redondearAlPeso: true,
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
    private function guardarLineas(Factura $factura, array $lineas, array $lineasCalculadas): void
    {
        foreach ($lineas as $i => $linea) {
            $factura->lineas()->create([
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

    /**
     * Intenta timbrar la factura contra facturapi.io; en éxito guarda sellos/UUID, en error la
     * deja en `pendiente` con el mensaje devuelto (ver 007-facturacion.md, supuesto #10).
     */
    private function intentarTimbrar(Factura $factura): void
    {
        $factura->load(['lineas.articulo', 'cliente']);

        $estabaTimbrada = $factura->estado === EstadoFactura::Timbrada;

        try {
            $respuesta = $this->facturapi->timbrarFactura($factura);

            $factura->update([
                'estado' => EstadoFactura::Timbrada->value,
                'facturapi_invoice_id' => $respuesta->id ?? null,
                'uuid_fiscal' => $respuesta->uuid ?? null,
                'facturapi_serie' => $respuesta->series ?? null,
                'facturapi_folio' => $respuesta->folio_number ?? null,
                'sello_cfdi' => $respuesta->stamp->signature ?? null,
                'sello_sat' => $respuesta->stamp->sat_signature ?? null,
                'cadena_original_sat' => $respuesta->stamp->complement_string ?? null,
                'no_certificado_sat' => $respuesta->stamp->sat_cert_number ?? null,
                'fecha_timbrado' => $respuesta->stamp->date ?? now(),
                'version_comprobante' => isset($respuesta->cfdi_version) ? (string) $respuesta->cfdi_version : null,
                'error_timbrado' => null,
            ]);

            // Salida de inventario (ver 017-inventario.md). Solo si la factura representa la venta
            // por sí sola: con cotización vinculada manda la cotización, y la salida ocurre al
            // marcarla como entregada. `$estabaTimbrada` evita descontar dos veces si se reintenta
            // el timbrado de una factura que ya lo estaba.
            if (! $estabaTimbrada && $factura->mueveInventario()) {
                $this->inventario->salidaPorDocumento(
                    $factura->lineas,
                    MotivoMovimientoInventario::VentaFactura,
                    $factura,
                );
            }
        } catch (FacturapiException $exception) {
            $factura->update([
                'estado' => EstadoFactura::Pendiente->value,
                'error_timbrado' => $exception->getMessage(),
            ]);
        }
    }

    private function refrescarEstadoCancelacion(Factura $factura): void
    {
        try {
            $respuesta = $this->facturapi->consultarFactura((string) $factura->facturapi_invoice_id);
            $estadoCancelacion = $respuesta->cancellation_status ?? null;

            if ($estadoCancelacion === null || $estadoCancelacion === EstadoCancelacion::None->value) {
                return;
            }

            $estado = EstadoCancelacion::from($estadoCancelacion);

            $factura->update([
                'estado_cancelacion' => $estado->value,
                'estado' => $estado === EstadoCancelacion::Accepted
                    ? EstadoFactura::Cancelada->value
                    : $factura->estado->value,
            ]);

            $this->devolverInventario($factura, $estado);
        } catch (FacturapiException) {
            // Si la consulta en vivo falla, se muestra el último estado conocido sin bloquear
            // la pantalla de detalle.
        }
    }

    /**
     * Devuelve las piezas al inventario cuando el SAT **acepta** la cancelación (ver
     * 017-inventario.md).
     *
     * Tres condiciones, todas necesarias:
     *
     * - La cancelación quedó `accepted`: mientras esté `pending`, la factura sigue vigente.
     * - La factura no tiene cotización vinculada: si la tiene, nunca descontó, y no se devuelve lo
     *   que nunca salió.
     * - No se devolvió ya antes, para que un refresco del estado de cancelación no repita la
     *   entrada.
     *
     * La devolución es una **entrada normal**: salda primero el faltante pendiente y el resto sube
     * la existencia. Reponer en cambio "lo que esta factura hizo" produciría estados imposibles
     * (existencia 5 con faltante 3) cuando hubo ventas posteriores.
     */
    private function devolverInventario(Factura $factura, EstadoCancelacion $estadoCancelacion): void
    {
        if ($estadoCancelacion !== EstadoCancelacion::Accepted || ! $factura->mueveInventario()) {
            return;
        }

        $yaDevuelta = MovimientoInventario::where('documentable_type', $factura->getMorphClass())
            ->where('documentable_id', $factura->id)
            ->where('motivo', MotivoMovimientoInventario::CancelacionFactura->value)
            ->exists();

        if ($yaDevuelta) {
            return;
        }

        $this->inventario->entradaPorDocumento(
            $factura->lineas()->get(),
            MotivoMovimientoInventario::CancelacionFactura,
            $factura,
        );
    }
}
