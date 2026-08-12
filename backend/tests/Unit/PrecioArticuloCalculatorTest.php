<?php

use App\Services\PrecioArticuloCalculator;

/**
 * Casos frontera de la cadena de precios, leídos del fixture compartido con el frontend
 * (ver 011-precio-proveedor-utilidad.md). El mismo archivo lo consume Vitest sobre
 * frontend/src/lib/precioArticulo.ts, de modo que cambiar una implementación sin la otra rompe la
 * suite del lado no tocado.
 */
function casosDelFixture(): array
{
    // Ruta calculada desde el archivo y no con base_path(), porque los datasets se resuelven antes
    // de que el framework esté disponible: tests/Unit -> tests -> backend -> raíz del repositorio.
    $ruta = dirname(__DIR__, 3).'/shared/fixtures/precios-articulos.json';

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

test('la cadena de precios coincide con el fixture compartido', function (array $caso) {
    $cadena = PrecioArticuloCalculator::calcularCadena(
        (float) $caso['precio_proveedor'],
        (float) $caso['descuento'],
        (float) $caso['utilidad_porcentaje'],
        (float) $caso['costo_goma'],
    );

    expect($cadena['costo_con_descuento'])->toBe((float) $caso['costo_con_descuento']);
    expect($cadena['costo_total'])->toBe((float) $caso['costo_total']);
    expect($cadena['precio_unitario_sin_iva'])->toBe((float) $caso['precio_unitario_sin_iva']);
    expect(PrecioArticuloCalculator::utilidad(
        $cadena['precio_unitario_sin_iva'],
        $cadena['costo_total'],
    ))->toBe((float) $caso['utilidad']);
})->with(casosDelFixture());

test('un articulo sin goma produce la misma cadena que antes del eslabon nuevo', function () {
    // Invariante de la migración: con costo_goma en 0 la fórmula nueva y la vieja son la misma
    // operación (ver 014-costo-elaboracion-goma.md). El barrido cubre descuentos y porcentajes
    // variados, no solo los casos frontera del fixture.
    foreach ([0.0, 10.0, 33.33, 55.0] as $descuento) {
        foreach ([0.0, 12.5, 25.0, 99.0, 300.0] as $porcentaje) {
            for ($precioCentavos = 1000; $precioCentavos <= 50000; $precioCentavos += 997) {
                $precio = $precioCentavos / 100;

                $conGoma = PrecioArticuloCalculator::calcularCadena($precio, $descuento, $porcentaje, 0.0);
                $costo = PrecioArticuloCalculator::costoConDescuento($precio, $descuento);

                expect($conGoma['costo_total'])->toBe($costo);
                expect($conGoma['precio_unitario_sin_iva'])
                    ->toBe(PrecioArticuloCalculator::techo2($costo * (1 + $porcentaje / 100)));
            }
        }
    }
});

test('el techo a 2 decimales redondea despues de escalar a centavos', function () {
    // La variante que redondea antes de escalar (ceil(round(v, 4) * 100) / 100) devuelve 16.18
    // aquí, porque 0.07 * 100 da 7.000000000000001 (ver 011).
    expect(PrecioArticuloCalculator::techo2(16.17))->toBe(16.17);
    expect(PrecioArticuloCalculator::techo2(0.07))->toBe(0.07);
    // Un valor con fracción de centavo real sí sube.
    expect(PrecioArticuloCalculator::techo2(133.0133))->toBe(133.02);
    // Un valor exacto no se altera.
    expect(PrecioArticuloCalculator::techo2(273.0))->toBe(273.0);
});

test('el techo nunca deja el precio por debajo del markup solicitado', function () {
    // Barrido sobre costos y porcentajes: el precio de venta redondeado nunca puede ser menor que
    // el valor exacto en centavos.
    foreach ([500, 1000, 1250, 3000, 3333, 5500, 9999] as $porcentajeBp) {
        for ($costoCentavos = 100; $costoCentavos <= 20000; $costoCentavos += 7) {
            $costo = $costoCentavos / 100;
            $porcentaje = $porcentajeBp / 100;

            $exactoCentavos = $costoCentavos * (10000 + $porcentajeBp);
            $esperado = intdiv($exactoCentavos, 10000) + ($exactoCentavos % 10000 ? 1 : 0);

            $obtenido = (int) round(PrecioArticuloCalculator::precioVentaSinIva($costo, $porcentaje) * 100);

            expect($obtenido)->toBe($esperado, "costo $costo con markup $porcentaje%");
        }
    }
});
