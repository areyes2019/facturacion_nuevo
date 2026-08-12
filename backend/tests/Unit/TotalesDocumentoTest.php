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
    );

    $esperado = $caso['esperado'];

    expect($resultado['subtotal'])->toBe((float) $esperado['subtotal']);
    expect($resultado['total_descuento'])->toBe((float) $esperado['total_descuento']);
    expect($resultado['total_iva_16'])->toBe((float) $esperado['total_iva_16']);
    expect($resultado['total_iva_0'])->toBe((float) $esperado['total_iva_0']);
    expect($resultado['total_exento'])->toBe((float) $esperado['total_exento']);
    expect($resultado['total'])->toBe((float) $esperado['total']);

    expect($resultado['lineas'])->toHaveCount(count($esperado['lineas']));

    foreach ($esperado['lineas'] as $i => $lineaEsperada) {
        expect($resultado['lineas'][$i]['importe'])->toBe((float) $lineaEsperada['importe']);
        expect($resultado['lineas'][$i]['iva_importe'])->toBe((float) $lineaEsperada['iva_importe']);
    }
})->with(casosDeTotalesDelFixture());
