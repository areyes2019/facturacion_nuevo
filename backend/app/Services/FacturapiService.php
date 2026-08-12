<?php

namespace App\Services;

use App\Enums\MotivoCancelacion;
use App\Enums\TasaIva;
use App\Models\ComplementoPago;
use App\Models\Factura;
use Facturapi\Facturapi;
use Facturapi\InvoiceType;

/**
 * Envuelve el SDK oficial de PHP de facturapi.io (`facturapi/facturapi-php`). Cliente y
 * artículos se envían siempre inline en cada solicitud (ver 007-facturacion.md, adición 35);
 * no requiere sincronizar ningún identificador de antemano.
 *
 * Los nombres de campos del payload y de la respuesta de timbrado (`stamp.*`) fueron
 * verificados con llamadas reales contra el ambiente de pruebas de facturapi.io (ver
 * 007-facturacion.md, "Estado de implementación").
 */
class FacturapiService
{
    private Facturapi $client;

    public function __construct()
    {
        $env = config('services.facturapi.env', 'test');
        $apiKey = $env === 'live'
            ? config('services.facturapi.live_key')
            : config('services.facturapi.test_key');

        $this->client = new Facturapi((string) $apiKey, [
            'timeout' => (float) config('services.facturapi.timeout', 30),
        ]);
    }

    /**
     * Crea y timbra la factura de inmediato (sin usar el modo `draft` nativo, ver
     * 007-facturacion.md, supuesto #37).
     */
    public function timbrarFactura(Factura $factura): object
    {
        return (object) $this->client->Invoices->create($this->construirPayloadFactura($factura));
    }

    /**
     * @return object{
     *     cancellation_status: string,
     * }
     */
    public function cancelarFactura(Factura $factura, MotivoCancelacion $motivo, ?string $facturaSustitutaUuid): object
    {
        $query = ['motive' => $motivo->value];
        if ($facturaSustitutaUuid !== null) {
            $query['substitution'] = $facturaSustitutaUuid;
        }

        return (object) $this->client->Invoices->cancel($factura->facturapi_invoice_id, $query);
    }

    public function consultarFactura(string $facturapiInvoiceId): object
    {
        return (object) $this->client->Invoices->retrieve($facturapiInvoiceId);
    }

    /**
     * Descarga el XML timbrado en vivo; no se guarda copia local (ver 007-facturacion.md,
     * adición 42).
     */
    public function descargarXml(string $facturapiInvoiceId): string
    {
        return $this->client->Invoices->downloadXml($facturapiInvoiceId);
    }

    /**
     * Timbra el complemento de pago (CFDI tipo "P") de una factura PPD.
     */
    public function timbrarComplementoPago(ComplementoPago $complementoPago): object
    {
        return (object) $this->client->Invoices->create($this->construirPayloadComplementoPago($complementoPago));
    }

    /**
     * @return array<string, mixed>
     */
    private function construirPayloadFactura(Factura $factura): array
    {
        $cliente = $factura->cliente;
        $lineas = $factura->lineas->values();

        // El CFDI no tiene un nodo de descuento a nivel factura: se recalcula el mismo
        // prorrateo del descuento global que ya se usó al calcular los totales internos (ver
        // 007-facturacion.md, "Cálculo de totales e IVA con descuento global"), a partir de los
        // datos ya persistidos, y se suma al descuento manual de cada línea.
        $importesPorLinea = $lineas->map(fn ($linea) => (float) $linea->importe)->all();
        $descuentoGlobal = FacturaTotalesCalculator::calcularDescuento(
            (float) $factura->subtotal,
            $factura->descuento_global_tipo?->value,
            $factura->descuento_global_valor !== null ? (float) $factura->descuento_global_valor : null,
        );
        $prorrateo = FacturaTotalesCalculator::prorratear($importesPorLinea, (float) $factura->subtotal, $descuentoGlobal);

        $items = $lineas->map(function ($linea, $i) use ($prorrateo) {
            $articulo = $linea->articulo;
            $brutoLinea = round($linea->cantidad * (float) $linea->precio_unitario, 2);
            $descuentoLinea = round($brutoLinea - (float) $linea->importe, 2);
            $discount = round($descuentoLinea + $prorrateo[$i], 2);

            return [
                'quantity' => $linea->cantidad,
                'discount' => $discount,
                'product' => [
                    'description' => $linea->descripcion,
                    'product_key' => $articulo?->clave_prod_serv,
                    'unit_key' => $articulo?->clave_unidad,
                    'price' => (float) $linea->precio_unitario,
                    // Sin esto, facturapi.io asume por defecto que `price` ya incluye el IVA y
                    // lo extrae del precio en vez de sumarlo encima (verificado en vivo: sin
                    // esta bandera el total termina siendo menor al calculado por el sistema).
                    'tax_included' => false,
                    'taxes' => [$this->construirImpuestoLinea($linea->tasa_iva)],
                ],
            ];
        })->all();

        return [
            'type' => InvoiceType::INGRESO,
            'customer' => [
                'legal_name' => $cliente->razon_social,
                'tax_id' => $cliente->rfc,
                'email' => $cliente->correo_contacto,
                'tax_system' => $cliente->regimen_fiscal,
                'address' => ['zip' => $cliente->codigo_postal_fiscal],
            ],
            'items' => $items,
            'use' => $factura->uso_cfdi,
            'payment_form' => $factura->forma_pago,
            'payment_method' => $factura->metodo_pago->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function construirPayloadComplementoPago(ComplementoPago $complementoPago): array
    {
        $factura = $complementoPago->factura;

        return [
            'type' => InvoiceType::PAGO,
            'customer' => [
                'legal_name' => $factura->cliente->razon_social,
                'tax_id' => $factura->cliente->rfc,
                'email' => $factura->cliente->correo_contacto,
                'tax_system' => $factura->cliente->regimen_fiscal,
                'address' => ['zip' => $factura->cliente->codigo_postal_fiscal],
            ],
            // La estructura real (`complements[].data.related_documents[].amount/taxes.base`)
            // no coincide con la documentación pública; se verificó con una llamada real.
            'complements' => [
                [
                    'type' => 'pago',
                    'data' => [
                        'date' => $complementoPago->fecha_pago->toDateString().'T00:00:00',
                        'payment_form' => $complementoPago->forma_pago,
                        'related_documents' => [
                            [
                                'uuid' => (string) $factura->uuid_fiscal,
                                'installment' => 1,
                                'last_balance' => (float) $factura->total,
                                'amount' => (float) $complementoPago->monto,
                                // Simplificación: usa el IVA general de la factura completa; no
                                // prorratea entre tasas mixtas (16%/0%/exento) si el monto pagado
                                // difiere del total o hay líneas con distintas tasas.
                                'taxes' => [[
                                    'type' => 'IVA',
                                    'rate' => 0.16,
                                    'factor' => 'Tasa',
                                    'base' => (float) $factura->subtotal,
                                ]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function construirImpuestoLinea(TasaIva $tasaIva): array
    {
        if ($tasaIva === TasaIva::Exento) {
            return ['type' => 'IVA', 'factor' => 'Exento'];
        }

        return ['type' => 'IVA', 'rate' => $tasaIva->tasa(), 'factor' => 'Tasa'];
    }
}
