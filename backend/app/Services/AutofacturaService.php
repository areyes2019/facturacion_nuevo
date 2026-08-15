<?php

namespace App\Services;

use App\Enums\EstadoFactura;
use App\Enums\MetodoPago;
use App\Enums\TipoCuenta;
use App\Mail\FacturaEnviadaMail;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\Pedido;
use Facturapi\Exceptions\FacturapiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Convierte un pedido de mostrador ya pagado en una factura timbrada, con los datos fiscales que el
 * propio cliente capturó en el portal público (ver 027-venta-mostrador-ticket.md).
 *
 * Corre **sin sesión**: quien lo dispara es un cliente que no tiene cuenta en el sistema. Todo lo
 * que necesita sale del pedido que trae el token.
 */
class AutofacturaService
{
    /**
     * Forma de pago del CFDI según el tipo de cuenta que recibió el dinero. El cliente no tiene por
     * qué saber a qué cuenta entró su pago, y el usuario ya lo capturó al registrarlo.
     */
    private const FORMA_PAGO_POR_TIPO_CUENTA = [
        'efectivo' => '01',
        'banco' => '03',
        'digital' => '03',
        'otro' => '99',
    ];

    public function __construct(private readonly FacturapiService $facturapi) {}

    /**
     * @param  array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal_fiscal: string, uso_cfdi: string, correo: string}  $datos
     *
     * @throws RuntimeException con un motivo ya redactado en español para el cliente.
     */
    public function facturar(Pedido $pedido, array $datos): Factura
    {
        $motivo = $pedido->motivoAutofacturaNoDisponible();

        if ($motivo !== null) {
            throw new RuntimeException($motivo);
        }

        // Todo dentro de una transacción: un timbrado fallido no puede dejar a medias una factura
        // sin sellos ni un pedido marcado como facturado. El token sigue vivo y el cliente
        // reintenta con los datos corregidos.
        try {
            return DB::transaction(function () use ($pedido, $datos) {
                $cliente = $this->clienteFiscal($pedido, $datos);
                $factura = $this->crearFactura($pedido, $cliente, $datos);

                // El vínculo se escribe ANTES de timbrar: el timbrado consulta `mueveInventario()`
                // y con `pedidos.factura_id` aún sin guardar vería una venta de mostrador
                // independiente y descontaría por segunda vez lo que el pedido ya descontó (mismo
                // orden que impuso 017 al vínculo factura → cotización).
                $pedido->update(['factura_id' => $factura->id]);

                $this->timbrar($factura);

                $pedido->autofactura_error = null;
                $pedido->save();

                return $factura;
            });
        } catch (FacturapiException $exception) {
            $this->registrarFallo($pedido, $exception->getMessage());

            throw new RuntimeException($this->traducir($exception->getMessage()));
        }
    }

    /**
     * Da de alta al cliente en el catálogo fiscal, o reusa el que ya exista con ese RFC.
     *
     * Aquí sí entra al catálogo, a diferencia del cliente de mostrador: ya trae RFC y régimen, que
     * es justo lo que le faltaba.
     */
    private function clienteFiscal(Pedido $pedido, array $datos): Cliente
    {
        $rfc = strtoupper(trim($datos['rfc']));

        $existente = $pedido->user->clientes()->where('rfc', $rfc)->first();

        if ($existente !== null) {
            return $existente;
        }

        return $pedido->user->clientes()->create([
            'rfc' => $rfc,
            'razon_social' => $datos['razon_social'],
            'regimen_fiscal' => $datos['regimen_fiscal'],
            'codigo_postal_fiscal' => $datos['codigo_postal_fiscal'],
            'correo_contacto' => $datos['correo'],
            'telefono' => $pedido->cliente_telefono,
        ]);
    }

    /**
     * La factura con los mismos importes del ticket. `metodo_pago` es siempre PUE: el enlace de
     * autofactura solo existe cuando el pedido quedó totalmente pagado, así que nunca hay saldo
     * fiscal pendiente que complementar.
     */
    private function crearFactura(Pedido $pedido, Cliente $cliente, array $datos): Factura
    {
        $pedido->loadMissing('lineas');

        $siguienteFolio = ((int) Factura::where('user_id', $pedido->user_id)->max('folio')) + 1;

        $factura = $pedido->user->facturas()->create([
            'cliente_id' => $cliente->id,
            'folio' => $siguienteFolio,
            'estado' => EstadoFactura::Borrador->value,
            'uso_cfdi' => $datos['uso_cfdi'],
            'forma_pago' => $this->formaPagoDe($pedido),
            'metodo_pago' => MetodoPago::Pue->value,
            'descuento_global_tipo' => $pedido->descuento_global_tipo?->value,
            'descuento_global_valor' => $pedido->descuento_global_valor,
            'subtotal' => $pedido->subtotal,
            'total_descuento' => $pedido->total_descuento,
            'total_iva_16' => $pedido->total_iva_16,
            'total_iva_0' => $pedido->total_iva_0,
            'total_exento' => $pedido->total_exento,
            'total' => $pedido->total,
        ]);

        foreach ($pedido->lineas as $linea) {
            $factura->lineas()->create([
                'articulo_id' => $linea->articulo_id,
                'cantidad' => $linea->cantidad,
                'descripcion' => $linea->descripcion,
                'modelo' => $linea->modelo ?? '',
                'precio_unitario' => $linea->precio_unitario,
                'descuento_tipo' => $linea->descuento_tipo?->value,
                'descuento_valor' => $linea->descuento_valor,
                'tasa_iva' => $linea->tasa_iva->value,
                'importe' => $linea->importe,
                'iva_importe' => $linea->iva_importe,
            ]);
        }

        return $factura;
    }

    /**
     * Se deriva del tipo de la cuenta que recibió el último pago. Sin pagos con cuenta —solo
     * posible si se corrigió el historial a mano— cae en "Por definir".
     */
    private function formaPagoDe(Pedido $pedido): string
    {
        $ultimo = $pedido->pagos()->whereNotNull('cuenta_id')->with('cuenta')->latest('id')->first();
        $tipo = $ultimo?->cuenta?->tipo;

        if ($tipo === null) {
            return self::FORMA_PAGO_POR_TIPO_CUENTA['otro'];
        }

        $valor = $tipo instanceof TipoCuenta ? $tipo->value : (string) $tipo;

        return self::FORMA_PAGO_POR_TIPO_CUENTA[$valor] ?? self::FORMA_PAGO_POR_TIPO_CUENTA['otro'];
    }

    /**
     * Timbra y guarda los sellos. **No descuenta inventario**: `mueveInventario()` ya devuelve
     * `false` porque el pedido apunta a esta factura.
     *
     * @throws FacturapiException
     */
    private function timbrar(Factura $factura): void
    {
        $factura->load(['lineas.articulo', 'cliente']);

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
    }

    /**
     * Le manda la factura por correo al cliente, con el mismo mailable que ya usa el envío manual.
     *
     * Un fallo aquí no invalida el timbrado: la factura existe y está en el detalle del pedido. Se
     * registra en el log y el cliente ve el acuse igual.
     */
    public function enviarPorCorreo(Factura $factura, string $correo): void
    {
        try {
            $xml = $this->facturapi->descargarXml((string) $factura->facturapi_invoice_id);
            $factura->load(['cliente', 'lineas.articulo' => fn ($relacion) => $relacion->withTrashed()]);
            $pdf = app('dompdf.wrapper')->loadView('pdf.factura', ['factura' => $factura])->output();

            Mail::to($correo)->send(new FacturaEnviadaMail($factura, $pdf, $xml));
        } catch (\Throwable $exception) {
            Log::warning('No se pudo enviar por correo una factura de autofactura.', [
                'factura' => $factura->id,
                'motivo' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Deja constancia del intento fallido en el pedido, fuera de la transacción que se revirtió.
     *
     * Es la única forma de que el usuario se entere: ese cliente ya se fue y no va a insistir.
     */
    private function registrarFallo(Pedido $pedido, string $motivo): void
    {
        $pedido->refresh();
        $pedido->autofactura_error = $motivo;
        $pedido->save();
    }

    /**
     * Traduce los rechazos más comunes de facturapi.io a algo que un cliente entienda.
     *
     * Sin esto, quien está del otro lado lee un código y cierra la pestaña. Lo que no esté mapeado
     * cae en una frase genérica pero honesta, nunca en el mensaje crudo del proveedor.
     */
    private function traducir(string $mensaje): string
    {
        $texto = mb_strtolower($mensaje);

        return match (true) {
            str_contains($texto, 'zip') || str_contains($texto, 'postal') => 'El código postal no coincide con el que el SAT tiene registrado para ese RFC. Revísalo en tu Constancia de Situación Fiscal.',
            str_contains($texto, 'tax_id') || str_contains($texto, 'rfc') => 'El SAT no reconoce ese RFC. Revísalo en tu Constancia de Situación Fiscal.',
            str_contains($texto, 'tax_system') || str_contains($texto, 'regimen') || str_contains($texto, 'régimen') => 'El régimen fiscal no corresponde al que el SAT tiene registrado para ese RFC.',
            str_contains($texto, 'legal_name') || str_contains($texto, 'name') => 'La razón social no coincide exactamente con la que el SAT tiene registrada. Escríbela tal como aparece en tu Constancia de Situación Fiscal, sin el régimen de capital.',
            str_contains($texto, 'use') => 'El uso de CFDI elegido no es válido para ese RFC. Prueba con otro.',
            default => 'No se pudo generar la factura en este momento. Revisa tus datos e inténtalo de nuevo; si sigue fallando, contacta al negocio.',
        };
    }
}
