<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\FacturapiService;
use PhpCfdi\Rfc\RfcFaker;

/**
 * Descuento permanente por cliente (ver 015-descuento-permanente-cliente.md).
 */
function clienteConDescuento(User $user, float $descuento): Cliente
{
    return Cliente::factory()->for($user)->create([
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Ferretería López SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
        'descuento_permanente' => $descuento,
    ]);
}

function articuloParaDescuento(User $user): Articulo
{
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    return Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Sello de goma',
        'modelo' => 'MOD-1234',
        'clave_prod_serv' => '43211503',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
    ]);
}

/**
 * Línea del caso de referencia de la spec: $333.33 × 3 con 15% de descuento.
 */
function lineaConDescuento(Articulo $articulo, array $overrides = []): array
{
    return array_merge([
        'articulo_id' => $articulo->id,
        'cantidad' => 3,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 333.33,
        'descuento_tipo' => 'porcentaje',
        'descuento_valor' => 15,
        'tasa_iva' => '16',
    ], $overrides);
}

function timbradoExitosoParaDescuento(): object
{
    return (object) [
        'id' => 'inv_test_015',
        'uuid' => '8ff503a2-c6b7-4a25-9999-a25610e6b488',
        'series' => 'F',
        'folio_number' => 1500,
        'cfdi_version' => 4,
        'stamp' => (object) [
            'signature' => 'SELLO_CFDI_DE_PRUEBA',
            'sat_signature' => 'SELLO_SAT_DE_PRUEBA',
            'sat_cert_number' => '20001000000300022323',
            'date' => '2026-08-08T12:00:00',
            'complement_string' => '||1.1|8ff503a2-c6b7-4a25-9999-a25610e6b488|2026-08-08T12:00:00|SELLO_CFDI_DE_PRUEBA|20001000000300022323||',
        ],
    ];
}

// --- Ficha del cliente -------------------------------------------------------------------------

// El tipo del dato de prueba se conserva tal cual (int|float, no float) porque PHP serializa un
// float redondo como entero y `assertJsonPath` compara con identidad — misma convención que 014.
test('se puede capturar un descuento permanente entre 0 y 50 por ciento', function (int|float $descuento) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', [
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Ferretería López SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
        'descuento_permanente' => $descuento,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.descuento_permanente', $descuento);
})->with([0, 12.5, 50]);

test('un descuento permanente mayor a 50 por ciento se rechaza', function (int|float $descuento) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', [
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Ferretería López SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
        'descuento_permanente' => $descuento,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('descuento_permanente');
})->with([50.01, 51, 100, -1]);

test('un cliente creado sin descuento queda en cero', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', [
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Ferretería López SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.descuento_permanente', 0);
});

test('un descuento enviado en blanco equivale a cero', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 15);

    $response = $this->actingAs($user)->putJson("/api/v1/clientes/{$cliente->id}", [
        'rfc' => $cliente->rfc,
        'razon_social' => $cliente->razon_social,
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
        'descuento_permanente' => '',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.descuento_permanente', 0);
});

test('el descuento de un cliente no es modificable por otro usuario', function () {
    $dueno = User::factory()->create();
    $ajeno = User::factory()->create();
    $cliente = clienteConDescuento($dueno, 15);

    $this->actingAs($ajeno)->putJson("/api/v1/clientes/{$cliente->id}", [
        'rfc' => $cliente->rfc,
        'razon_social' => $cliente->razon_social,
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
        'descuento_permanente' => 50,
    ])->assertNotFound();

    expect((float) $cliente->fresh()->descuento_permanente)->toBe(15.0);
});

// --- Copia congelada en la cotización ----------------------------------------------------------

test('una cotizacion congela el descuento que tenia el cliente al capturarla', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 10);
    $articulo = articuloParaDescuento($user);

    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo, ['descuento_valor' => 10])],
        'total' => 1043.99,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.descuento_cliente_porcentaje', 10);

    // Subir el descuento del cliente no mueve la cotización ya guardada.
    $cliente->update(['descuento_permanente' => 20]);

    $this->actingAs($user)->getJson("/api/v1/cotizaciones/{$response->json('data.id')}")
        ->assertJsonPath('data.descuento_cliente_porcentaje', 10);
});

test('editar una cotizacion sin cambiar de cliente conserva el descuento congelado', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 10);
    $articulo = articuloParaDescuento($user);

    $cotizacion = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo, ['descuento_valor' => 10])],
        'total' => 1043.99,
    ])->json('data.id');

    $cliente->update(['descuento_permanente' => 20]);

    $this->actingAs($user)->putJson("/api/v1/cotizaciones/{$cotizacion}", [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo, ['descuento_valor' => 10])],
        'total' => 1043.99,
    ])->assertOk()->assertJsonPath('data.descuento_cliente_porcentaje', 10);
});

test('editar una cotizacion cambiando de cliente reemplaza el descuento congelado', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 10);
    $otroCliente = clienteConDescuento($user, 30);
    $articulo = articuloParaDescuento($user);

    $cotizacion = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo, ['descuento_valor' => 10])],
        'total' => 1043.99,
    ])->json('data.id');

    $this->actingAs($user)->putJson("/api/v1/cotizaciones/{$cotizacion}", [
        'cliente_id' => $otroCliente->id,
        'lineas' => [lineaConDescuento($articulo, ['descuento_valor' => 30])],
        'total' => 811.99,
    ])->assertOk()->assertJsonPath('data.descuento_cliente_porcentaje', 30);
});

test('duplicar una cotizacion copia el descuento congelado del original y no el vigente', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 10);
    $articulo = articuloParaDescuento($user);

    $cotizacion = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo, ['descuento_valor' => 10])],
        'total' => 1043.99,
    ])->json('data.id');

    $cliente->update(['descuento_permanente' => 45]);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion}/duplicar")
        ->assertCreated()
        ->assertJsonPath('data.descuento_cliente_porcentaje', 10);
});

// --- Precio unitario de facturación ------------------------------------------------------------

test('el precio de facturacion pliega el descuento de linea dentro del precio', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 15);
    $articulo = articuloParaDescuento($user);

    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo)],
        'total' => 986.0,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.lineas.0.precio_unitario', 333.33);
    $response->assertJsonPath('data.lineas.0.importe', 849.99);
    $response->assertJsonPath('data.lineas.0.precio_unitario_facturacion', 283.33);
});

test('una linea sin descuento factura al mismo precio unitario', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 0);
    $articulo = articuloParaDescuento($user);

    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo, ['descuento_tipo' => null, 'descuento_valor' => null])],
        'total' => 1159.99,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.lineas.0.precio_unitario_facturacion', 333.33);
});

test('el precio de facturacion tambien vale para un descuento de tipo monto', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 0);
    $articulo = articuloParaDescuento($user);

    // $100.00 × 2 = $200.00 menos $50.00 de descuento = $150.00 → $75.00 por pieza.
    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo, [
            'cantidad' => 2,
            'precio_unitario' => 100.00,
            'descuento_tipo' => 'monto',
            'descuento_valor' => 50,
        ])],
        'total' => 174.00,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.lineas.0.precio_unitario_facturacion', 75);
});

test('el residuo de centavos al dividir entre la cantidad se acepta sin compensar', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 0);
    $articulo = articuloParaDescuento($user);

    // $50.00 × 3 = $150.00 menos $50.00 = $100.00 → $33.33 por pieza (la factura totaliza $99.99).
    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo, [
            'precio_unitario' => 50.00,
            'descuento_tipo' => 'monto',
            'descuento_valor' => 50,
        ])],
        'total' => 116.00,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.lineas.0.importe', 100);
    $response->assertJsonPath('data.lineas.0.precio_unitario_facturacion', 33.33);
});

// --- Equivalencia de totales cotización → factura ----------------------------------------------

test('la factura precargada desde la cotizacion paga el mismo total sin mostrar descuento', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 15);
    $articulo = articuloParaDescuento($user);

    $cotizacion = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo)],
        'total' => 986.0,
    ])->json('data');

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(timbradoExitosoParaDescuento());
    });

    // Exactamente lo que precarga el formulario de factura: precio de facturación y descuentos en
    // null (ver 015-descuento-permanente-cliente.md).
    $factura = $this->actingAs($user)->postJson('/api/v1/facturas', [
        'cliente_id' => $cliente->id,
        'cotizacion_id' => $cotizacion['id'],
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 3,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => $cotizacion['lineas'][0]['precio_unitario_facturacion'],
            'descuento_tipo' => null,
            'descuento_valor' => null,
            'tasa_iva' => '16',
        ]],
        'total' => 986.0,
    ]);

    $factura->assertCreated();
    $factura->assertJsonPath('data.total', $cotizacion['total']);
    $factura->assertJsonPath('data.total_iva_16', $cotizacion['total_iva_16']);
    $factura->assertJsonPath('data.subtotal', $cotizacion['subtotal']);
    // El descuento desaparece del documento fiscal: en la cotización eran $150.00.
    $factura->assertJsonPath('data.total_descuento', 0);
    expect($cotizacion['total_descuento'])->toBe(150);
});

test('el descuento global de la cotizacion si viaja visible a la factura', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 15);
    $articulo = articuloParaDescuento($user);

    $cotizacion = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo)],
        'descuento_global_tipo' => 'porcentaje',
        'descuento_global_valor' => 10,
        'total' => 888.0,
    ])->json('data');

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(timbradoExitosoParaDescuento());
    });

    $factura = $this->actingAs($user)->postJson('/api/v1/facturas', [
        'cliente_id' => $cliente->id,
        'cotizacion_id' => $cotizacion['id'],
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 3,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => $cotizacion['lineas'][0]['precio_unitario_facturacion'],
            'descuento_tipo' => null,
            'descuento_valor' => null,
            'tasa_iva' => '16',
        ]],
        'descuento_global_tipo' => 'porcentaje',
        'descuento_global_valor' => 10,
        'total' => 888.0,
    ]);

    $factura->assertCreated();
    $factura->assertJsonPath('data.total', $cotizacion['total']);
    // Solo sobrevive el descuento global; el de línea quedó plegado en el precio.
    $factura->assertJsonPath('data.total_descuento', 85);
});

// --- La factura desde cero no aplica nada ------------------------------------------------------

test('una factura creada desde cero no aplica el descuento permanente del cliente', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 30);
    $articulo = articuloParaDescuento($user);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(timbradoExitosoParaDescuento());
    });

    $factura = $this->actingAs($user)->postJson('/api/v1/facturas', [
        'cliente_id' => $cliente->id,
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 2,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 232.00,
    ]);

    $factura->assertCreated();
    $factura->assertJsonPath('data.total_descuento', 0);
    $factura->assertJsonPath('data.total', 232);
});

test('el descuento permanente no toca las cotizaciones de un cliente sin descuento', function () {
    $user = User::factory()->create();
    $cliente = clienteConDescuento($user, 0);
    $articulo = articuloParaDescuento($user);

    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaConDescuento($articulo, [
            'cantidad' => 2,
            'precio_unitario' => 100.00,
            'descuento_tipo' => null,
            'descuento_valor' => null,
        ])],
        'total' => 232.00,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.descuento_cliente_porcentaje', 0);
    $response->assertJsonPath('data.total_descuento', 0);
    $response->assertJsonPath('data.total', 232);

    expect(Cotizacion::find($response->json('data.id'))->descuento_cliente_porcentaje)->toBe('0.00');
});
