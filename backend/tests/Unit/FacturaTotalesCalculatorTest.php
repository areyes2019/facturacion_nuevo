<?php

use App\Services\FacturaTotalesCalculator;

test('el descuento global reduce la base del iva de la unica linea (caso del reporte de bug)', function () {
    // Subtotal $1000, descuento global $500 (monto fijo), IVA 16% sobre $500 = $80, total $580.
    $resultado = FacturaTotalesCalculator::calcular(
        [[
            'cantidad' => 1,
            'precio_unitario' => 1000.0,
            'descuento_tipo' => null,
            'descuento_valor' => null,
            'tasa_iva' => '16',
        ]],
        'monto',
        500.0,
    );

    expect($resultado['subtotal'])->toBe(1000.0);
    expect($resultado['total_descuento'])->toBe(500.0);
    expect($resultado['total_iva_16'])->toBe(80.0);
    expect($resultado['total'])->toBe(580.0);
    expect($resultado['lineas'][0]['importe'])->toBe(1000.0);
    expect($resultado['lineas'][0]['iva_importe'])->toBe(80.0);
});

test('el descuento global se prorratea entre varias lineas proporcional a su importe', function () {
    // Línea A: $700 (70% del subtotal de $1000), línea B: $300 (30%).
    // Descuento global de $500 (monto fijo) se reparte 350/150; IVA 16% sobre (700-350)=350 y
    // (300-150)=150 → 56 + 24 = 80. Total = 1000 - 500 + 80 = 580.
    $resultado = FacturaTotalesCalculator::calcular(
        [
            [
                'cantidad' => 1,
                'precio_unitario' => 700.0,
                'descuento_tipo' => null,
                'descuento_valor' => null,
                'tasa_iva' => '16',
            ],
            [
                'cantidad' => 1,
                'precio_unitario' => 300.0,
                'descuento_tipo' => null,
                'descuento_valor' => null,
                'tasa_iva' => '16',
            ],
        ],
        'monto',
        500.0,
    );

    expect($resultado['total_iva_16'])->toBe(80.0);
    expect($resultado['total'])->toBe(580.0);
    // El "importe" (Total de la fila) no se ve afectado por el descuento global, solo el IVA.
    expect($resultado['lineas'][0]['importe'])->toBe(700.0);
    expect($resultado['lineas'][1]['importe'])->toBe(300.0);
    expect($resultado['lineas'][0]['iva_importe'])->toBe(56.0);
    expect($resultado['lineas'][1]['iva_importe'])->toBe(24.0);
});

test('el prorrateo del descuento global no deja residuos de redondeo entre lineas', function () {
    // 3 líneas de $100 cada una (subtotal $300) con un descuento global de $100 (monto fijo):
    // 100/3 = 33.33... por línea; la última línea debe absorber el residuo para que la suma
    // de las partes sea exactamente $100, no $99.99 ni $100.02.
    $partes = FacturaTotalesCalculator::prorratear([100.0, 100.0, 100.0], 300.0, 100.0);

    expect(array_sum($partes))->toBe(100.0);
    expect($partes[0])->toBe(33.33);
    expect($partes[1])->toBe(33.33);
    expect($partes[2])->toBe(33.34);
});

test('sin descuento global el prorrateo es cero y el resultado es igual al calculo anterior', function () {
    $resultado = FacturaTotalesCalculator::calcular(
        [[
            'cantidad' => 2,
            'precio_unitario' => 100.0,
            'descuento_tipo' => null,
            'descuento_valor' => null,
            'tasa_iva' => '16',
        ]],
        null,
        null,
    );

    expect($resultado['subtotal'])->toBe(200.0);
    expect($resultado['total_descuento'])->toBe(0.0);
    expect($resultado['total_iva_16'])->toBe(32.0);
    expect($resultado['total'])->toBe(232.0);
});
