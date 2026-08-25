<?php

namespace App\Models;

use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cotizacion_id',
    'articulo_id',
    'cantidad',
    'descripcion',
    'modelo',
    'precio_unitario',
    'costo_unitario',
    'descuento_tipo',
    'descuento_valor',
    'tasa_iva',
    'importe',
    'iva_importe',
])]
class CotizacionLinea extends Model
{
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    /**
     * Precio unitario con el que esta línea viaja a la factura: el descuento de línea plegado
     * dentro del precio, para que el descuento sea transparente al documento fiscal (ver
     * 015-descuento-permanente-cliente.md).
     *
     * Se deriva del `importe` ya persistido (el neto del propio descuento de la línea, antes del
     * descuento global) en vez de repetir la resta, de modo que la fórmula vale igual para un
     * descuento de tipo `porcentaje` que de tipo `monto`. Una línea sin descuento devuelve el mismo
     * `precio_unitario`.
     *
     * Vive solo en el backend a propósito: el frontend consume el número ya hecho y no lo
     * recalcula, para no duplicar el redondeo y terminar con descuadres de centavos entre lo que se
     * le mostró al cliente y lo que se timbró (mismo riesgo que 008 documenta para los totales).
     *
     * Dividir entre la cantidad puede dejar un residuo: con `importe` $100.00 y cantidad 3, el
     * precio es $33.33 y la factura totaliza $99.99. Se acepta y no se compensa; el precio sigue
     * siendo editable en el formulario de factura.
     */
    protected function precioUnitarioFacturacion(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->cantidad > 0
                ? round((float) $this->importe / $this->cantidad, 2)
                : (float) $this->precio_unitario,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:2',
            'costo_unitario' => 'decimal:2',
            'descuento_tipo' => TipoDescuento::class,
            'descuento_valor' => 'decimal:2',
            'tasa_iva' => TasaIva::class,
            'importe' => 'decimal:2',
            'iva_importe' => 'decimal:2',
        ];
    }
}
