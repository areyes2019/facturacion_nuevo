<?php

namespace App\Models;

use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de un pedido de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * Mismo esquema que `CotizacionLinea`, con una diferencia que sí importa: `articulo_id` puede venir
 * en `null`. Esa es la **línea libre**, algo que se vendió en el mostrador y no está en el catálogo.
 * No mueve inventario y al facturarse se timbra con claves SAT genéricas.
 */
#[Fillable([
    'pedido_id',
    'articulo_id',
    'cantidad',
    'descripcion',
    'modelo',
    'precio_unitario',
    'descuento_tipo',
    'descuento_valor',
    'tasa_iva',
    'importe',
    'iva_importe',
])]
class PedidoLinea extends Model
{
    protected $table = 'pedido_lineas';

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function esLibre(): bool
    {
        return $this->articulo_id === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:2',
            'descuento_tipo' => TipoDescuento::class,
            'descuento_valor' => 'decimal:2',
            'tasa_iva' => TasaIva::class,
            'importe' => 'decimal:2',
            'iva_importe' => 'decimal:2',
        ];
    }
}
