<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Collection;

/**
 * Utilidad de venta de un documento (Cotización o Pedido), a partir del costo capturado por línea
 * al momento de vender (ver 010-tesoreria.md, "Utilidad de venta en movimientos automáticos").
 *
 * Se usa desde `Cotizacion` y `Pedido`, cuyas líneas comparten la misma forma: `articulo_id`
 * (nullable en Pedido, línea libre), `cantidad`, `importe` (neto de línea, ya sin IVA y ya con
 * descuentos aplicados) y `costo_unitario` (copia del costo del artículo al guardar la línea).
 *
 * @property Collection $lineas
 */
trait CalculaUtilidadVenta
{
    /**
     * @return array{utilidad: float|null, utilidad_parcial: bool}
     */
    public function utilidadVenta(): array
    {
        $lineas = $this->lineas;

        $lineasConArticulo = $lineas->filter(fn ($linea) => $linea->articulo_id !== null);
        $hayLineaLibre = $lineasConArticulo->count() < $lineas->count();

        $faltaCosto = $lineasConArticulo->isEmpty()
            || $lineasConArticulo->contains(fn ($linea) => $linea->costo_unitario === null);

        if ($faltaCosto) {
            return ['utilidad' => null, 'utilidad_parcial' => false];
        }

        $utilidad = $lineasConArticulo->sum(
            fn ($linea) => (float) $linea->importe - ((float) $linea->costo_unitario * $linea->cantidad)
        );

        return ['utilidad' => round($utilidad, 2), 'utilidad_parcial' => $hayLineaLibre];
    }
}
