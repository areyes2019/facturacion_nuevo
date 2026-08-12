<?php

namespace App\Services;

use App\Enums\TasaIva;
use App\Enums\TipoDescuento;

/**
 * Calcula los importes de cada línea y los totales generales de una factura a partir de los
 * datos crudos recibidos (cantidad, precio unitario, descuento, tasa de IVA). El backend
 * siempre recalcula con esta clase, tanto al guardar como al validar que el total enviado por
 * el frontend coincida (ver 007-facturacion.md, adición técnica I).
 *
 * El CFDI no tiene un nodo de "descuento a nivel factura" (ver 007-facturacion.md, "Cálculo de
 * totales e IVA con descuento global"): el descuento global se prorratea entre las líneas
 * (proporcional a su importe) antes de calcular el IVA de cada una, para que el descuento
 * reduzca la base gravable como corresponde fiscalmente, y para que `FacturapiService` pueda
 * enviar esa misma porción prorrateada dentro del `discount` de cada ítem a facturapi.io.
 */
class FacturaTotalesCalculator
{
    /**
     * @param  array<int, array{cantidad: int, precio_unitario: float, descuento_tipo: ?string, descuento_valor: ?float, tasa_iva: string}>  $lineas
     * @return array{
     *     lineas: array<int, array{importe: float, iva_importe: float}>,
     *     subtotal: float,
     *     total_descuento: float,
     *     total_iva_16: float,
     *     total_iva_0: float,
     *     total_exento: float,
     *     total: float,
     * }
     */
    public static function calcular(
        array $lineas,
        ?string $descuentoGlobalTipo,
        ?float $descuentoGlobalValor,
    ): array {
        // Primera pasada: importe de cada línea neto de su propio descuento, antes del global.
        $importesPorLinea = [];
        $subtotal = 0.0;
        $totalDescuentoLineas = 0.0;

        foreach ($lineas as $linea) {
            $bruto = round($linea['cantidad'] * $linea['precio_unitario'], 2);
            $descuentoLinea = self::calcularDescuento($bruto, $linea['descuento_tipo'] ?? null, $linea['descuento_valor'] ?? null);
            $importe = round($bruto - $descuentoLinea, 2);

            $importesPorLinea[] = $importe;
            $subtotal += $importe;
            $totalDescuentoLineas += $descuentoLinea;
        }

        $subtotal = round($subtotal, 2);
        $descuentoGlobal = self::calcularDescuento($subtotal, $descuentoGlobalTipo, $descuentoGlobalValor);
        $prorrateo = self::prorratear($importesPorLinea, $subtotal, $descuentoGlobal);

        // Segunda pasada: IVA de cada línea sobre el importe ya neto de su parte del descuento
        // global.
        $ivaPorTasa = [
            TasaIva::General->value => 0.0,
            TasaIva::Cero->value => 0.0,
            TasaIva::Exento->value => 0.0,
        ];

        $lineasCalculadas = [];
        foreach ($lineas as $i => $linea) {
            $importe = $importesPorLinea[$i];
            $importeNetoFinal = round($importe - $prorrateo[$i], 2);

            $tasa = TasaIva::from($linea['tasa_iva']);
            $ivaImporte = round($importeNetoFinal * $tasa->tasa(), 2);
            $ivaPorTasa[$tasa->value] += $ivaImporte;

            // El "importe" que se persiste y se muestra como "Total" de la fila sigue siendo el
            // neto antes del descuento global (ver 007-facturacion.md, adición #31); solo el IVA
            // (invisible por línea, usado nada más para los totales generales) refleja el
            // descuento global ya prorrateado.
            $lineasCalculadas[] = ['importe' => $importe, 'iva_importe' => $ivaImporte];
        }

        $totalIva16 = round($ivaPorTasa[TasaIva::General->value], 2);
        $totalIva0 = round($ivaPorTasa[TasaIva::Cero->value], 2);
        $totalExento = round($ivaPorTasa[TasaIva::Exento->value], 2);

        $total = round($subtotal - $descuentoGlobal + $totalIva16 + $totalIva0, 2);

        return [
            'lineas' => $lineasCalculadas,
            'subtotal' => $subtotal,
            'total_descuento' => round($totalDescuentoLineas + $descuentoGlobal, 2),
            'total_iva_16' => $totalIva16,
            'total_iva_0' => $totalIva0,
            'total_exento' => $totalExento,
            'total' => $total,
        ];
    }

    /**
     * Reparte el monto monetario del descuento global entre las líneas, proporcional al importe
     * de cada una (antes de ese descuento), con la última línea absorbiendo el residuo de
     * redondeo para que la suma de las partes sea exactamente igual al monto del descuento
     * global (evita descuadres de centavos). Pública para que `FacturapiService` pueda
     * recalcular la misma porción por línea al armar el payload de facturapi.io a partir de una
     * `Factura` ya persistida.
     *
     * @param  array<int, float>  $importesPorLinea
     * @return array<int, float>
     */
    public static function prorratear(array $importesPorLinea, float $subtotal, float $descuentoGlobal): array
    {
        $total = count($importesPorLinea);

        if ($descuentoGlobal <= 0.0 || $subtotal <= 0.0 || $total === 0) {
            return array_fill(0, $total, 0.0);
        }

        $partes = [];
        $acumulado = 0.0;
        $ultimo = $total - 1;

        foreach (array_values($importesPorLinea) as $i => $importe) {
            if ($i === $ultimo) {
                $partes[] = round($descuentoGlobal - $acumulado, 2);

                break;
            }

            $parte = round($descuentoGlobal * $importe / $subtotal, 2);
            $partes[] = $parte;
            $acumulado += $parte;
        }

        return $partes;
    }

    /**
     * Pública para que `FacturapiService` pueda recalcular el monto monetario del descuento
     * global a partir de una `Factura` ya persistida (`subtotal`, `descuento_global_tipo`,
     * `descuento_global_valor`), sin duplicar esta lógica.
     */
    public static function calcularDescuento(float $base, ?string $tipo, ?float $valor): float
    {
        if ($tipo === null || $valor === null) {
            return 0.0;
        }

        return round(
            TipoDescuento::from($tipo) === TipoDescuento::Porcentaje
                ? $base * ($valor / 100)
                : $valor,
            2,
        );
    }
}
