<?php

namespace App\Models;

use App\Enums\EstadoCancelacion;
use App\Enums\EstadoFactura;
use App\Enums\MetodoPago;
use App\Enums\MotivoCancelacion;
use App\Enums\TipoDescuento;
use Database\Factories\FacturaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'cliente_id',
    'folio',
    'estado',
    'uso_cfdi',
    'forma_pago',
    'metodo_pago',
    'moneda',
    'tipo_comprobante',
    'descuento_global_tipo',
    'descuento_global_valor',
    'subtotal',
    'total_descuento',
    'total_iva_16',
    'total_iva_0',
    'total_exento',
    'total',
    'facturapi_invoice_id',
    'uuid_fiscal',
    'facturapi_serie',
    'facturapi_folio',
    'sello_cfdi',
    'sello_sat',
    'cadena_original_sat',
    'no_certificado_sat',
    'fecha_timbrado',
    'version_comprobante',
    'error_timbrado',
    'motivo_cancelacion',
    'factura_sustituta_id',
    'fecha_cancelacion',
    'estado_cancelacion',
])]
class Factura extends Model
{
    /** @use HasFactory<FacturaFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(FacturaLinea::class);
    }

    public function complementoPago(): HasOne
    {
        return $this->hasOne(ComplementoPago::class);
    }

    public function facturaSustituta(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'factura_sustituta_id');
    }

    /**
     * Cotización de la que se originó esta factura, si aplica (ver 008-cotizaciones.md). El
     * vínculo vive en `cotizaciones.factura_id`, no en una columna de `facturas`.
     */
    public function cotizacion(): HasOne
    {
        return $this->hasOne(Cotizacion::class);
    }

    /**
     * El pedido de mostrador que se autofacturó con esta factura, si lo hay. Igual que con la
     * cotización, el vínculo vive en `pedidos.factura_id` (ver 027-venta-mostrador-ticket.md).
     */
    public function pedido(): HasOne
    {
        return $this->hasOne(Pedido::class);
    }

    /**
     * Una factura mueve inventario solo cuando representa la venta por sí sola (ver
     * 017-inventario.md).
     *
     * Con cotización vinculada manda la cotización: la salida ocurre al marcarla como entregada,
     * porque la mercancía sale cuando sale físicamente, no cuando se emite el papel fiscal. Esa
     * factura no descuenta al timbrarse ni devuelve al cancelarse.
     *
     * Con pedido vinculado manda el pedido por la misma razón, solo que ahí la mercancía salió
     * todavía antes: en el mostrador, al levantar la venta (ver 027-venta-mostrador-ticket.md). Sin
     * esta condición, autofacturar descontaría por segunda vez lo que ya se entregó en mano.
     */
    public function mueveInventario(): bool
    {
        return ! $this->cotizacion()->exists() && ! $this->pedido()->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoFactura::class,
            'metodo_pago' => MetodoPago::class,
            'descuento_global_tipo' => TipoDescuento::class,
            'descuento_global_valor' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'total_iva_16' => 'decimal:2',
            'total_iva_0' => 'decimal:2',
            'total_exento' => 'decimal:2',
            'total' => 'decimal:2',
            'facturapi_folio' => 'integer',
            'fecha_timbrado' => 'datetime',
            'motivo_cancelacion' => MotivoCancelacion::class,
            'fecha_cancelacion' => 'datetime',
            'estado_cancelacion' => EstadoCancelacion::class,
        ];
    }
}
