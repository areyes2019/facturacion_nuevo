<?php

use App\Enums\ObjetoImpuesto;
use App\Services\PrecioArticuloCalculator;

/**
 * Casos frontera de la cadena de precios, leídos del fixture compartido con el frontend
 * (ver 011-precio-proveedor-utilidad.md y 024-precios-sin-centavos.md). El mismo archivo lo consume
 * Vitest sobre frontend/src/lib/precioArticulo.ts, de modo que cambiar una implementación sin la
 * otra rompe la suite del lado no tocado.
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
    $objetoImp = ObjetoImpuesto::from($caso['objeto_imp']);

    $cadena = PrecioArticuloCalculator::calcularCadena(
        (float) $caso['precio_proveedor'],
        (float) $caso['descuento'],
        (float) $caso['utilidad_porcentaje'],
        (float) $caso['costo_goma'],
        $objetoImp,
        (float) $caso['utilidad_distribuidor_porcentaje'],
    );

    expect($cadena['costo_con_descuento'])->toBe((float) $caso['costo_con_descuento']);
    expect($cadena['costo_total'])->toBe((float) $caso['costo_total']);

    // El crudo se verifica aparte del final para que un fallo distinga si se rompió el markup o el
    // redondeo (ver 024).
    expect(PrecioArticuloCalculator::precioVentaSinIva(
        $cadena['costo_total'],
        (float) $caso['utilidad_porcentaje'],
    ))->toBe((float) $caso['precio_venta_crudo_sin_iva']);

    expect($cadena['precio_unitario_sin_iva'])->toBe((float) $caso['precio_unitario_sin_iva']);
    expect(PrecioArticuloCalculator::redondeo2(
        $cadena['precio_unitario_sin_iva'] * PrecioArticuloCalculator::factorIva($objetoImp),
    ))->toBe((float) $caso['precio_unitario_con_iva']);
    expect(PrecioArticuloCalculator::utilidad(
        $cadena['precio_unitario_sin_iva'],
        $cadena['costo_total'],
    ))->toBe((float) $caso['utilidad']);

    // El precio distribuidor (033) parte de costo_con_descuento, nunca de costo_total: se verifica
    // aparte del crudo por la misma razón que el directo, y contra costo_con_descuento y no contra
    // costo_total para que un fallo delate si alguien coló la goma en la cuenta del distribuidor.
    expect(PrecioArticuloCalculator::precioVentaSinIva(
        $cadena['costo_con_descuento'],
        (float) $caso['utilidad_distribuidor_porcentaje'],
    ))->toBe((float) $caso['precio_distribuidor_venta_crudo_sin_iva']);

    expect($cadena['precio_distribuidor_sin_iva'])->toBe((float) $caso['precio_distribuidor_sin_iva']);
    expect(PrecioArticuloCalculator::redondeo2(
        $cadena['precio_distribuidor_sin_iva'] * PrecioArticuloCalculator::factorIva($objetoImp),
    ))->toBe((float) $caso['precio_distribuidor_con_iva']);
})->with(casosDelFixture());

test('el precio distribuidor nunca lleva el costo de la goma', function () {
    // Invariante de 033: con el mismo costo_con_descuento, el precio distribuidor es idéntico tenga
    // o no tenga goma el artículo, porque la goma nunca entra a su cadena.
    foreach ([0.0, 10.0, 20.0] as $costoGoma) {
        $cadena = PrecioArticuloCalculator::calcularCadena(200.0, 0.0, 50.0, $costoGoma, ObjetoImpuesto::SiObjeto, 25.0);

        expect($cadena['precio_distribuidor_sin_iva'])->toBe(250.0);
    }
});

test('sin argumento de utilidad distribuidor, calcularCadena sigue funcionando con solo tres argumentos', function () {
    // Migraciones ya aplicadas llaman a calcularCadena con solo precio_proveedor, descuento y
    // utilidad_porcentaje, apoyadas en los valores por defecto de costo_goma, objeto_imp y
    // utilidad_distribuidor_porcentaje (ver 011, 014 y 024). Este test es la red de esa compatibilidad.
    $cadena = PrecioArticuloCalculator::calcularCadena(200.0, 0.0, 50.0);

    expect($cadena['precio_unitario_sin_iva'])->toBe(300.0);
    expect($cadena['precio_distribuidor_sin_iva'])->toBe(200.0);
});

test('un articulo sin goma produce el mismo costo que antes del eslabon de la goma', function () {
    // Invariante de la migración de 014: con costo_goma en 0 el costo total es el costo del aparato.
    // El precio de venta sí cambió al agregar el redondeo de 024, así que la invariante se verifica
    // sobre el costo y sobre el precio CRUDO, que es lo que 014 dejó definido.
    foreach ([0.0, 10.0, 33.33, 55.0] as $descuento) {
        foreach ([0.0, 12.5, 25.0, 99.0, 300.0] as $porcentaje) {
            for ($precioCentavos = 1000; $precioCentavos <= 50000; $precioCentavos += 997) {
                $precio = $precioCentavos / 100;

                $cadena = PrecioArticuloCalculator::calcularCadena($precio, $descuento, $porcentaje, 0.0);
                $costo = PrecioArticuloCalculator::costoConDescuento($precio, $descuento);

                expect($cadena['costo_total'])->toBe($costo);
                expect(PrecioArticuloCalculator::precioVentaSinIva($costo, $porcentaje))
                    ->toBe(PrecioArticuloCalculator::techo2($costo * (1 + $porcentaje / 100)));
            }
        }
    }
});

test('el redondeo deja el precio con IVA en un peso entero sin bajar el markup', function () {
    // Barrido de 024: es la prueba que detecta los enteros inalcanzables. Una batería de casos
    // escogidos a mano no los habría encontrado, y de hecho fue así como aparecieron.
    //
    // Las fallas se acumulan y se afirman una sola vez al final: 400,000 aserciones dentro del ciclo
    // tardan más que el barrido completo, y el listado de las primeras desviaciones es más útil que
    // el mensaje de una aserción suelta.
    $fallas = [];

    foreach ([1.16, 1.0] as $factor) {
        for ($crudoCentavos = 1; $crudoCentavos <= 200000; $crudoCentavos++) {
            $crudo = $crudoCentavos / 100;
            $final = PrecioArticuloCalculator::redondearAPesoEntero($crudo, $factor);
            $conIva = PrecioArticuloCalculator::redondeo2($final * $factor);
            $ajuste = $conIva - PrecioArticuloCalculator::redondeo2($crudo * $factor);

            // 1. El precio que ve el cliente es un peso entero exacto.
            // 2. El redondeo nunca baja el precio, así que nunca erosiona el markup.
            // 3. El ajuste se mantiene por debajo de dos pesos: nunca hacen falta dos incrementos de
            //    objetivo, porque no hay inalcanzables consecutivos.
            if ($conIva !== floor($conIva) || $final < $crudo || $ajuste >= 2.0) {
                $fallas[] = "crudo $crudo con factor $factor -> $final (con IVA $conIva)";
            }
        }
    }

    expect(array_slice($fallas, 0, 5))->toBe([]);
});

test('con factor 1 el redondeo es un techo al peso y siempre acierta al primer objetivo', function () {
    expect(PrecioArticuloCalculator::redondearAPesoEntero(201.28, 1.0))->toBe(202.0);
    expect(PrecioArticuloCalculator::redondearAPesoEntero(150.0, 1.0))->toBe(150.0);
    expect(PrecioArticuloCalculator::redondearAPesoEntero(0.01, 1.0))->toBe(1.0);
});

test('el factor de IVA sale del objeto de impuesto del articulo', function () {
    expect(PrecioArticuloCalculator::factorIva(ObjetoImpuesto::SiObjeto))->toBe(1.16);
    expect(PrecioArticuloCalculator::factorIva(ObjetoImpuesto::NoObjeto))->toBe(1.0);
    expect(PrecioArticuloCalculator::factorIva(ObjetoImpuesto::SiObjetoNoDesglose))->toBe(1.0);
    expect(PrecioArticuloCalculator::factorIva(ObjetoImpuesto::SiObjetoNoCausaImpuesto))->toBe(1.0);
    expect(PrecioArticuloCalculator::factorIva(null))->toBe(1.0);
});

test('un precio crudo de cero se queda en cero sin forzar un minimo', function () {
    expect(PrecioArticuloCalculator::redondearAPesoEntero(0.0, 1.16))->toBe(0.0);
    expect(PrecioArticuloCalculator::redondearAPesoEntero(0.0, 1.0))->toBe(0.0);
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
