<?php

use App\Services\ConstanciaFiscal\ParesPorPosicion;

/**
 * Ver 016-constancia-situacion-fiscal-qr.md.
 *
 * Las coordenadas son las de una constancia real —el domicilio en dos columnas, la primera a la
 * izquierda del todo y la segunda a poco más de la mitad de la hoja— con el contenido cambiado por
 * datos ficticios. Lo que se prueba es la geometría, que es lo que rompía la extracción: cada
 * palabra viaja como un trozo aparte, sin espacios, y dos pares distintos comparten renglón.
 */
function trozosDomicilio(): array
{
    return [
        // "Código Postal: 06700" y "Tipo de Vialidad: CALLE", en el mismo renglón.
        [35.90, 248.72, 'Código'], [65.68, 248.72, 'Postal:'], [92.35, 248.72, '06700'],
        [310.90, 248.72, 'Tipo'], [330.01, 248.72, 'de'], [341.57, 248.72, 'Vialidad:'], [377.14, 248.72, 'CALLE'],

        // "Nombre de Vialidad: ORIZABA" y "Número Exterior: 87".
        [35.90, 226.87, 'Nombre'], [68.35, 226.87, 'de'], [79.91, 226.87, 'Vialidad:'], [115.48, 226.87, 'ORIZABA'],
        [310.90, 226.87, 'Número'], [343.35, 226.87, 'Exterior:'], [378.47, 226.87, '87'],

        // "Número Interior:" sin valor, junto a la colonia, que sí lo tiene y de dos palabras.
        [35.90, 205.02, 'Número'], [68.35, 205.02, 'Interior:'],
        [310.90, 205.02, 'Nombre'], [343.35, 205.02, 'de'], [354.91, 205.02, 'la'], [363.80, 205.02, 'Colonia:'],
        [398.03, 205.02, 'ROMA'], [430.92, 205.02, 'NORTE'],

        // "Nombre de la Localidad:" vacía, junto al municipio.
        [35.90, 183.17, 'Nombre'], [68.35, 183.17, 'de'], [79.91, 183.17, 'la'], [88.80, 183.17, 'Localidad:'],
        [310.90, 183.17, 'Nombre'], [343.35, 183.17, 'del'], [357.13, 183.17, 'Municipio'], [396.69, 183.17, 'o'],
        [403.80, 183.17, 'Demarcación'], [456.27, 183.17, 'Territorial:'], [498.50, 183.17, 'CUAUHTEMOC'],

        // La entidad no cabe en la caja y se parte: "LA" queda en el renglón de abajo.
        [35.90, 165.94, 'Nombre'], [70.03, 165.94, 'de'], [83.27, 165.94, 'la'], [93.85, 165.94, 'Entidad'],
        [127.09, 165.94, 'Federativa:'], [173.68, 165.95, 'CIUDAD'], [221.58, 165.95, 'DE'], [236.60, 165.95, 'MEXICO'],
        [274.29, 165.95, 'ZONA'], [289.31, 165.95, 'DE'],
        [35.90, 156.70, 'LA'],

        // En la otra columna, a una altura distinta, hay otro par completo.
        [310.90, 161.32, 'Entre'], [333.57, 161.32, 'Calle:'], [359.80, 161.32, 'ALTAR'], [387.81, 161.32, 'DE'],
        [401.15, 161.32, 'PIEDRA'],
    ];
}

test('las dos columnas del domicilio no se mezclan', function () {
    $pares = (new ParesPorPosicion)->analizar(trozosDomicilio())['pares'];

    // Mientras el valor se buscó hasta los dos puntos siguientes, la vialidad se quedaba con la
    // etiqueta de al lado —"ORIZABA Número Exterior"— y el número exterior se perdía entero.
    expect($pares['nombredevialidad'])->toBe('ORIZABA');
    expect($pares['numeroexterior'])->toBe('87');
    expect($pares['codigopostal'])->toBe('06700');
    expect($pares['tipodevialidad'])->toBe('CALLE');
});

test('una etiqueta sin valor no se queda con el par de al lado', function () {
    $pares = (new ParesPorPosicion)->analizar(trozosDomicilio())['pares'];

    expect($pares)->not->toHaveKey('numerointerior');
    expect($pares['nombredelacolonia'])->toBe('ROMA NORTE');
    expect($pares['nombredelmunicipioodemarcacionterritorial'])->toBe('CUAUHTEMOC');
});

test('los espacios que el pdf no guarda se reponen entre palabras', function () {
    $pares = (new ParesPorPosicion)->analizar(trozosDomicilio())['pares'];

    // Cada palabra es un trozo aparte y el PDF no guarda ningún espacio: sin reponerlos, la colonia
    // saldría como "ROMANORTE".
    expect($pares['nombredelacolonia'])->toBe('ROMA NORTE');
});

test('un valor partido en dos renglones se une con su continuación', function () {
    $pares = (new ParesPorPosicion)->analizar(trozosDomicilio())['pares'];

    expect($pares['nombredelaentidadfederativa'])->toBe('CIUDAD DE MEXICO ZONA DE LA');
});

test('la continuación se une a la columna correcta y no al renglón anterior', function () {
    $pares = (new ParesPorPosicion)->analizar(trozosDomicilio())['pares'];

    // "LA" queda debajo de la entidad, pero el renglón inmediatamente anterior por altura es el de
    // "Entre Calle:", en la otra columna. Sin mirar la columna, la continuación se pegaría ahí.
    expect($pares['entrecalle'])->toBe('ALTAR DE PIEDRA');
});

test('una etiqueta y su valor en columnas distintas forman un solo par', function () {
    // Así es la tabla de identificación: "RFC:" a la izquierda y el RFC a la derecha del todo.
    $pares = (new ParesPorPosicion)->analizar([
        [35.90, 478.38, 'RFC:'],
        [235.90, 478.52, 'GOMR850712QX1'],
    ])['pares'];

    expect($pares['rfc'])->toBe('GOMR850712QX1');
});

test('las celdas sin etiqueta se devuelven aparte', function () {
    // Los regímenes de la constancia impresa son una tabla sin etiquetas: cada renglón es la
    // descripción a secas, y por ahí es por donde se recuperan.
    $resultado = (new ParesPorPosicion)->analizar([
        [35.90, 400.00, 'Régimen'], [200.00, 400.00, 'Fecha'], [260.00, 400.00, 'Inicio'],
        [35.90, 380.00, 'Régimen'], [66.00, 380.00, 'de'], [80.00, 380.00, 'Sueldos'],
        [200.00, 380.00, '30/01/2025'],
    ]);

    expect($resultado['sueltas'])->toContain('Régimen de Sueldos');
    expect($resultado['pares'])->toBe([]);
});
