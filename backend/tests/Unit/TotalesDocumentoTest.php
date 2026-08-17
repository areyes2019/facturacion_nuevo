<?php

use App\Services\FacturaTotalesCalculator;

/**
 * Casos del cálculo de totales de un documento, leídos del fixture compartido con el frontend
 * (ver 012-ordenes-compra.md, adición técnica 42). El mismo archivo lo consume Vitest sobre
 * frontend/src/lib/totalesDocumento.ts, de modo que cambiar una implementación sin la otra rompe
 * la suite del lado no tocado.
 *
 * La duplicación existe por necesidad: PHP es quien persiste y quien manda, TypeScript alimenta el
 * desglose en vivo del formulario sin depender de la red. Que existan dos implementaciones es
 * aceptable; que puedan divergir en silencio, no.
 */
function casosDeTotalesDelFixture(): array
{
    // Ruta calculada desde el archivo y no con base_path(), porque los datasets se resuelven antes
    // de que el framework esté disponible: tests/Unit -> tests -> backend -> raíz del repositorio.
    $ruta = dirname(__DIR__, 3).'/shared/fixtures/totales-documento.json';

    if (! file_exists($ruta)) {
        throw new RuntimeException("No se encontró el fixture compartido en $ruta");
    }

    $fixture = json_decode((string) file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);

    $casos = [];

    foreach ($fixture['casos'] as $caso) {
        $casos[$caso['descripcion']] = [$caso];
    }

    return $casos;
}

test('los totales del documento coinciden con el fixture compartido', function (array $caso) {
    $resultado = FacturaTotalesCalculator::calcular(
        $caso['lineas'],
        $caso['descuento_global_tipo'],
        $caso['descuento_global_valor'],
        $caso['redondear_al_peso'],
    );

    $esperado = $caso['esperado'];

    expect($resultado['subtotal'])->toBe((float) $esperado['subtotal']);
    expect($resultado['total_descuento'])->toBe((float) $esperado['total_descuento']);
    expect($resultado['total_iva_16'])->toBe((float) $esperado['total_iva_16']);
    expect($resultado['total_iva_0'])->toBe((float) $esperado['total_iva_0']);
    expect($resultado['total_exento'])->toBe((float) $esperado['total_exento']);
    expect($resultado['ajuste_al_peso'])->toBe((float) $esperado['ajuste_al_peso']);
    expect($resultado['total'])->toBe((float) $esperado['total']);

    expect($resultado['lineas'])->toHaveCount(count($esperado['lineas']));

    foreach ($esperado['lineas'] as $i => $lineaEsperada) {
        expect($resultado['lineas'][$i]['importe'])->toBe((float) $lineaEsperada['importe']);
        expect($resultado['lineas'][$i]['iva_importe'])->toBe((float) $lineaEsperada['iva_importe']);
    }
})->with(casosDeTotalesDelFixture());

/**
 * Barrido de 030-total-al-peso-cerrado.md: con un artículo cuyo precio con IVA es un peso cerrado
 * —el que garantiza 024—, cualquier cantidad hasta 190 piezas debe dar un total que sea exactamente
 * el múltiplo de ese precio. Es la prueba que detecta el desfase acumulado del IVA por renglón;
 * una batería de cantidades escogidas a mano no lo habría encontrado.
 */
test('el total ajustado es el multiplo exacto del precio con IVA hasta 190 piezas', function () {
    $precioSinIva = 175.86;
    $precioConIva = 204.00;

    for ($cantidad = 1; $cantidad <= 190; $cantidad++) {
        $resultado = FacturaTotalesCalculator::calcular(
            [[
                'cantidad' => $cantidad,
                'precio_unitario' => $precioSinIva,
                'descuento_tipo' => null,
                'descuento_valor' => null,
                'tasa_iva' => '16',
            ]],
            null,
            null,
            redondearAlPeso: true,
        );

        expect($resultado['total'])->toBe(round($cantidad * $precioConIva, 2));
        expect($resultado['ajuste_al_peso'])->toBeGreaterThanOrEqual(0.0);
        expect($resultado['ajuste_al_peso'])->toBeLessThan(1.0);
    }
});

test('el ajuste al peso nunca baja el total ni pasa de un peso', function () {
    for ($centavos = 0; $centavos <= 200_000; $centavos++) {
        $total = round($centavos / 100, 2);
        $ajuste = FacturaTotalesCalculator::ajusteAlPeso($total);

        expect($ajuste)->toBeGreaterThanOrEqual(0.0);
        expect($ajuste)->toBeLessThan(1.0);

        $ajustado = round($total + $ajuste, 2);
        $centavosFinales = (int) round($ajustado * 100) % 100;

        // O quedó en peso cerrado, o se quedó dentro de la tolerancia por encima de uno.
        expect($centavosFinales === 0 || $centavosFinales <= 5)->toBeTrue();
    }
});
