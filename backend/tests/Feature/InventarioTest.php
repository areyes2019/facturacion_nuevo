<?php

use App\Enums\EstadoCancelacion;
use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\EstadoOrdenCompra;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\FacturapiService;
use PhpCfdi\Rfc\RfcFaker;

/**
 * Artículo con costo y precio conocidos, para que las cifras de dinero del inventario sean
 * verificables a mano: costo total 100 (80 de aparato + 20 de goma), precio de venta 150,
 * utilidad 50 por pieza.
 */
function articuloParaInventario(User $user, array $overrides = []): Articulo
{
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 0]);

    return Articulo::factory()->for($user)->for($catalogo)->create(array_merge([
        'nombre' => 'Sello automático',
        'modelo' => 'MOD-1234',
        'precio_proveedor' => 80.00,
        'costo_con_descuento' => 80.00,
        'costo_goma' => 20.00,
        'precio_unitario_sin_iva' => 150.00,
    ], $overrides));
}

/**
 * Deja una orden de compra pagada, lista para recibirse.
 *
 * @param  array<int, array{articulo_id: ?int, cantidad: int}>  $lineas
 */
function ordenPagadaCon(User $user, Articulo $articulo, array $lineas): OrdenCompra
{
    $orden = OrdenCompra::factory()->for($user)->for($articulo->catalogo->proveedor)->create([
        'estado' => EstadoOrdenCompra::Pagada->value,
        'fecha_pago' => now(),
    ]);

    foreach ($lineas as $linea) {
        $orden->lineas()->create([
            'articulo_id' => $linea['articulo_id'],
            'cantidad' => $linea['cantidad'],
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 80.00,
            'tasa_iva' => '16',
            'importe' => 80.00 * $linea['cantidad'],
            'iva_importe' => 12.80 * $linea['cantidad'],
        ]);
    }

    return $orden;
}

function clienteParaVenta(User $user): Cliente
{
    return Cliente::factory()->for($user)->create([
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Comercializadora Ejemplo SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
    ]);
}

/**
 * Respuesta de timbrado exitosa. Propia de este archivo (y no la de `FacturasTest`) para que estos
 * tests no dependan del orden en que Pest cargue los archivos.
 */
function timbradoExitosoParaInventario(): object
{
    return (object) [
        'id' => 'inv_test_inventario',
        'uuid' => '8ff503a2-c6b7-4a25-9999-a25610e6b488',
        'series' => 'F',
        'folio_number' => 1433,
        'cfdi_version' => 4,
        'stamp' => (object) [
            'signature' => 'SELLO_CFDI_DE_PRUEBA',
            'sat_signature' => 'SELLO_SAT_DE_PRUEBA',
            'sat_cert_number' => '20001000000300022323',
            'date' => '2026-08-10T12:00:00',
            'complement_string' => '||1.1|8ff503a2-c6b7-4a25-9999-a25610e6b488|2026-08-10T12:00:00|SELLO|20001000000300022323||',
        ],
    ];
}

/** Fija la existencia de un artículo sin pasar por el servicio, para preparar un escenario. */
function conExistencia(Articulo $articulo, int $existencia, int $faltante = 0): Articulo
{
    $articulo->forceFill(['existencia' => $existencia, 'faltante_pendiente' => $faltante])->save();

    return $articulo->refresh();
}

test('un invitado no puede acceder al inventario', function () {
    $this->getJson('/api/v1/inventario')->assertUnauthorized();
});

// ---------------------------------------------------------------------------------------------
// Entradas: recepción de órdenes de compra
// ---------------------------------------------------------------------------------------------

test('recibir una orden pagada suma las cantidades de sus lineas al inventario', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);
    $orden = ordenPagadaCon($user, $articulo, [['articulo_id' => $articulo->id, 'cantidad' => 7]]);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")->assertOk();

    expect($articulo->refresh()->existencia)->toBe(7);
    $this->assertDatabaseHas('movimientos_inventario', [
        'articulo_id' => $articulo->id,
        'tipo' => 'entrada',
        'motivo' => 'recepcion_orden',
        'cantidad' => 7,
        'existencia_resultante' => 7,
        'documentable_type' => OrdenCompra::class,
        'documentable_id' => $orden->id,
    ]);
});

test('recibir dos veces la misma orden suma una sola vez', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);
    $orden = ordenPagadaCon($user, $articulo, [['articulo_id' => $articulo->id, 'cantidad' => 5]]);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")->assertOk();
    // El segundo intento choca con la validación de estado: la orden ya no está pagada.
    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")->assertUnprocessable();

    expect($articulo->refresh()->existencia)->toBe(5);
    expect($articulo->movimientosInventario()->count())->toBe(1);
});

test('las lineas sin articulo se ignoran al recibir', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);
    $orden = ordenPagadaCon($user, $articulo, [
        ['articulo_id' => $articulo->id, 'cantidad' => 3],
        ['articulo_id' => null, 'cantidad' => 99],
    ]);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")->assertOk();

    expect($articulo->refresh()->existencia)->toBe(3);
    expect($articulo->movimientosInventario()->count())->toBe(1);
});

test('dos lineas del mismo articulo suman su total y dejan un solo movimiento', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);
    $orden = ordenPagadaCon($user, $articulo, [
        ['articulo_id' => $articulo->id, 'cantidad' => 10],
        ['articulo_id' => $articulo->id, 'cantidad' => 5],
    ]);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")->assertOk();

    expect($articulo->refresh()->existencia)->toBe(15);
    expect($articulo->movimientosInventario()->count())->toBe(1);
    expect($articulo->movimientosInventario()->first()->cantidad)->toBe(15);
});

test('recibir con faltante pendiente salda primero el faltante', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 0, 3);
    $orden = ordenPagadaCon($user, $articulo, [['articulo_id' => $articulo->id, 'cantidad' => 10]]);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")->assertOk();

    $articulo->refresh();
    expect($articulo->existencia)->toBe(7);
    expect($articulo->faltante_pendiente)->toBe(0);
});

test('una entrada menor al faltante lo reduce sin subir la existencia', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 0, 3);
    $orden = ordenPagadaCon($user, $articulo, [['articulo_id' => $articulo->id, 'cantidad' => 2]]);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")->assertOk();

    $articulo->refresh();
    expect($articulo->existencia)->toBe(0);
    expect($articulo->faltante_pendiente)->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Salidas: facturas y cotizaciones
// ---------------------------------------------------------------------------------------------

test('timbrar una factura sin cotizacion vinculada descuenta el inventario', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 10);
    $cliente = clienteParaVenta($user);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(timbradoExitosoParaInventario());
    });

    $this->actingAs($user)->postJson('/api/v1/facturas', [
        'cliente_id' => $cliente->id,
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 4,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 150.00,
            'tasa_iva' => '16',
        ]],
        'total' => 696.00,
    ])->assertCreated();

    expect($articulo->refresh()->existencia)->toBe(6);
    $this->assertDatabaseHas('movimientos_inventario', [
        'articulo_id' => $articulo->id,
        'tipo' => 'salida',
        'motivo' => 'venta_factura',
        'cantidad' => 4,
    ]);
});

test('timbrar una factura con cotizacion vinculada no mueve el inventario', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 10);
    $cliente = clienteParaVenta($user);

    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create([
        'estado' => EstadoCotizacion::Pagada->value,
    ]);
    $cotizacion->lineas()->create([
        'articulo_id' => $articulo->id,
        'cantidad' => 4,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 150.00,
        'tasa_iva' => '16',
        'importe' => 600.00,
        'iva_importe' => 96.00,
    ]);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(timbradoExitosoParaInventario());
    });

    $this->actingAs($user)->postJson('/api/v1/facturas', [
        'cliente_id' => $cliente->id,
        'cotizacion_id' => $cotizacion->id,
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 4,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 150.00,
            'tasa_iva' => '16',
        ]],
        'total' => 696.00,
    ])->assertCreated();

    // Nada se movió: la salida ocurrirá al marcar la cotización como entregada.
    expect($articulo->refresh()->existencia)->toBe(10);
    expect($articulo->movimientosInventario()->count())->toBe(0);
});

test('marcar una cotizacion como entregada descuenta el inventario', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 10);
    // Total en 0 para que el saldo pendiente sea cero y la entrega cierre sola, sin pedir cuenta:
    // esta prueba mide el descuento de inventario, no el cobro (ver 038).
    $cotizacion = Cotizacion::factory()->for($user)->for(clienteParaVenta($user))->create([
        'estado' => EstadoCotizacion::Pagada->value,
        'total' => 0,
    ]);
    $cotizacion->lineas()->create([
        'articulo_id' => $articulo->id,
        'cantidad' => 3,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 150.00,
        'tasa_iva' => '16',
        'importe' => 450.00,
        'iva_importe' => 72.00,
    ]);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/entregar")->assertOk();

    expect($articulo->refresh()->existencia)->toBe(7);
    $this->assertDatabaseHas('movimientos_inventario', [
        'articulo_id' => $articulo->id,
        'motivo' => 'venta_cotizacion',
        'cantidad' => 3,
    ]);
});

test('vender mas de lo disponible deja existencia en cero y acumula el faltante', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 2);
    $cotizacion = Cotizacion::factory()->for($user)->for(clienteParaVenta($user))->create([
        'estado' => EstadoCotizacion::Pagada->value,
        'total' => 0,
    ]);
    $cotizacion->lineas()->create([
        'articulo_id' => $articulo->id,
        'cantidad' => 5,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 150.00,
        'tasa_iva' => '16',
        'importe' => 750.00,
        'iva_importe' => 120.00,
    ]);

    // La venta no se bloquea: el inventario arranca en cero y detenerla sería inusable.
    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/entregar")->assertOk();

    $articulo->refresh();
    expect($articulo->existencia)->toBe(0);
    expect($articulo->faltante_pendiente)->toBe(3);
});

// ---------------------------------------------------------------------------------------------
// Cancelación de factura
// ---------------------------------------------------------------------------------------------

test('cancelar una factura sin cotizacion devuelve las piezas saldando faltante primero', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 0, 3);
    $factura = Factura::factory()->for($user)->for(clienteParaVenta($user))->create([
        'estado' => EstadoFactura::Timbrada->value,
        'facturapi_invoice_id' => 'inv_test_123',
    ]);
    $factura->lineas()->create([
        'articulo_id' => $articulo->id,
        'cantidad' => 5,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 150.00,
        'tasa_iva' => '16',
        'importe' => 750.00,
        'iva_importe' => 120.00,
    ]);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('cancelarFactura')->once()
            ->andReturn((object) ['cancellation_status' => EstadoCancelacion::Accepted->value]);
    });

    $this->actingAs($user)->postJson("/api/v1/facturas/{$factura->id}/cancelar", [
        'motivo_cancelacion' => '02',
    ])->assertOk();

    // Entran 5: saldan el faltante de 3 y quedan 2 en existencia.
    $articulo->refresh();
    expect($articulo->existencia)->toBe(2);
    expect($articulo->faltante_pendiente)->toBe(0);
});

test('cancelar una factura con cotizacion vinculada no devuelve nada', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 4);
    $cliente = clienteParaVenta($user);

    $factura = Factura::factory()->for($user)->for($cliente)->create([
        'estado' => EstadoFactura::Timbrada->value,
        'facturapi_invoice_id' => 'inv_test_123',
    ]);
    $factura->lineas()->create([
        'articulo_id' => $articulo->id,
        'cantidad' => 5,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 150.00,
        'tasa_iva' => '16',
        'importe' => 750.00,
        'iva_importe' => 120.00,
    ]);

    Cotizacion::factory()->for($user)->for($cliente)->create([
        'estado' => EstadoCotizacion::ProductoEntregado->value,
        'factura_id' => $factura->id,
    ]);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('cancelarFactura')->once()
            ->andReturn((object) ['cancellation_status' => EstadoCancelacion::Accepted->value]);
    });

    $this->actingAs($user)->postJson("/api/v1/facturas/{$factura->id}/cancelar", [
        'motivo_cancelacion' => '02',
    ])->assertOk();

    // No se devuelve lo que nunca salió por esta factura.
    expect($articulo->refresh()->existencia)->toBe(4);
    expect($articulo->movimientosInventario()->count())->toBe(0);
});

test('una cancelacion pendiente no devuelve piezas', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 1);
    $factura = Factura::factory()->for($user)->for(clienteParaVenta($user))->create([
        'estado' => EstadoFactura::Timbrada->value,
        'facturapi_invoice_id' => 'inv_test_123',
    ]);
    $factura->lineas()->create([
        'articulo_id' => $articulo->id,
        'cantidad' => 2,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 150.00,
        'tasa_iva' => '16',
        'importe' => 300.00,
        'iva_importe' => 48.00,
    ]);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('cancelarFactura')->once()
            ->andReturn((object) ['cancellation_status' => EstadoCancelacion::Pending->value]);
    });

    $this->actingAs($user)->postJson("/api/v1/facturas/{$factura->id}/cancelar", [
        'motivo_cancelacion' => '02',
    ])->assertOk();

    // Mientras el SAT no acepte, la factura sigue vigente y la mercancía sigue fuera.
    expect($articulo->refresh()->existencia)->toBe(1);
});

// ---------------------------------------------------------------------------------------------
// Ajustes manuales
// ---------------------------------------------------------------------------------------------

test('un ajuste manual fija la cantidad final y pone el faltante en cero', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 12, 4);

    $this->actingAs($user)->postJson("/api/v1/inventario/{$articulo->id}/ajuste", [
        'cantidad' => 10,
        'motivo' => 'conteo_fisico',
        'nota' => 'Conteo de fin de mes',
    ])->assertOk()
        ->assertJsonPath('data.existencia', 10)
        ->assertJsonPath('data.faltante_pendiente', 0);

    $this->assertDatabaseHas('movimientos_inventario', [
        'articulo_id' => $articulo->id,
        'tipo' => 'ajuste',
        'motivo' => 'conteo_fisico',
        'cantidad' => 10,
        'existencia_resultante' => 10,
        'faltante_resultante' => 0,
        'nota' => 'Conteo de fin de mes',
    ]);
});

test('meter al inventario un articulo que nunca tuvo existencia usa el mismo ajuste', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);

    $this->actingAs($user)->postJson("/api/v1/inventario/{$articulo->id}/ajuste", [
        'cantidad' => 6,
        'motivo' => 'entrada_inicial',
    ])->assertOk()->assertJsonPath('data.existencia', 6);

    expect($articulo->refresh()->existencia)->toBe(6);
});

test('un ajuste con motivo automatico se rechaza', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);

    $this->actingAs($user)->postJson("/api/v1/inventario/{$articulo->id}/ajuste", [
        'cantidad' => 5,
        'motivo' => 'recepcion_orden',
    ])->assertUnprocessable()->assertJsonValidationErrors('motivo');

    expect($articulo->refresh()->existencia)->toBe(0);
});

test('un ajuste exige motivo', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);

    $this->actingAs($user)->postJson("/api/v1/inventario/{$articulo->id}/ajuste", ['cantidad' => 5])
        ->assertUnprocessable()->assertJsonValidationErrors('motivo');
});

// ---------------------------------------------------------------------------------------------
// Reposición
// ---------------------------------------------------------------------------------------------

test('un articulo esta por pedir cuando cae a su minimo o tiene faltante', function () {
    $user = User::factory()->create();

    $bajoMinimo = conExistencia(articuloParaInventario($user, ['modelo' => 'BAJO']), 5);
    $bajoMinimo->forceFill(['minimo' => 5, 'maximo' => 20])->save();

    $conFaltante = conExistencia(articuloParaInventario($user, ['modelo' => 'FALTA']), 0, 4);

    $sinMinimo = conExistencia(articuloParaInventario($user, ['modelo' => 'LIBRE']), 0);

    $response = $this->actingAs($user)->getJson('/api/v1/inventario?por_pedir=1&ver_todos=1');

    $response->assertOk();
    $modelos = collect($response->json('data'))->pluck('modelo');
    expect($modelos)->toContain('BAJO')->toContain('FALTA')->not->toContain('LIBRE');
});

test('la cantidad sugerida suma el faltante a lo que falta para el maximo', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user), 3, 4);
    $articulo->forceFill(['minimo' => 5, 'maximo' => 20])->save();

    $response = $this->actingAs($user)->getJson('/api/v1/inventario?por_pedir=1');

    // (20 − 3) + 4 = 21
    $response->assertOk()->assertJsonPath('data.0.cantidad_sugerida', 21);
});

test('generar ordenes de compra crea un borrador por proveedor con las cantidades sugeridas', function () {
    $user = User::factory()->create();

    $uno = conExistencia(articuloParaInventario($user, ['modelo' => 'A-1']), 2);
    $uno->forceFill(['minimo' => 5, 'maximo' => 10])->save();

    // Mismo proveedor que el anterior: deben caer en la misma orden.
    $dos = Articulo::factory()->for($user)->for($uno->catalogo)->create([
        'modelo' => 'A-2',
        'precio_proveedor' => 80.00,
        'costo_con_descuento' => 80.00,
        'costo_goma' => 0,
        'precio_unitario_sin_iva' => 150.00,
    ]);
    conExistencia($dos, 0)->forceFill(['minimo' => 4, 'maximo' => 4])->save();

    $otro = conExistencia(articuloParaInventario($user, ['modelo' => 'B-1']), 1);
    $otro->forceFill(['minimo' => 3, 'maximo' => 3])->save();

    $response = $this->actingAs($user)->postJson('/api/v1/inventario/generar-ordenes-compra');

    $response->assertCreated();
    expect($response->json('data'))->toHaveCount(2);

    $ordenes = OrdenCompra::with('lineas')->get();
    expect($ordenes)->toHaveCount(2);
    expect($ordenes->every(fn ($orden) => $orden->estado === EstadoOrdenCompra::Borrador))->toBeTrue();

    $delPrimerProveedor = $ordenes->firstWhere('proveedor_id', $uno->catalogo->proveedor_id);
    expect($delPrimerProveedor->lineas)->toHaveCount(2);
    expect($delPrimerProveedor->lineas->firstWhere('modelo', 'A-1')->cantidad)->toBe(8);
    expect($delPrimerProveedor->lineas->firstWhere('modelo', 'A-2')->cantidad)->toBe(4);
});

test('los articulos con catalogo eliminado se omiten y se reportan', function () {
    $user = User::factory()->create();
    $articulo = conExistencia(articuloParaInventario($user, ['modelo' => 'HUERFANO']), 0);
    $articulo->forceFill(['minimo' => 5, 'maximo' => 10])->save();
    $articulo->catalogo->delete();

    $response = $this->actingAs($user)->postJson('/api/v1/inventario/generar-ordenes-compra');

    $response->assertCreated();
    expect($response->json('data'))->toHaveCount(0);
    expect($response->json('omitidos'))->toHaveCount(1);
    $response->assertJsonPath('omitidos.0.modelo', 'HUERFANO');
    expect(OrdenCompra::count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Listado, totales y umbrales
// ---------------------------------------------------------------------------------------------

test('los totales corresponden al conjunto filtrado completo y no a la pagina', function () {
    $user = User::factory()->create();

    // 20 artículos con 3 piezas cada uno: 60 unidades, 60 × 100 = 6000 invertido,
    // 60 × 50 = 3000 de beneficio potencial. La página solo trae 15.
    foreach (range(1, 20) as $i) {
        conExistencia(articuloParaInventario($user, ['modelo' => "MOD-{$i}"]), 3);
    }

    $response = $this->actingAs($user)->getJson('/api/v1/inventario');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(15);
    $response->assertJsonPath('meta.totales.unidades', 60);
    $response->assertJsonPath('meta.totales.dinero_invertido', 6000);
    $response->assertJsonPath('meta.totales.beneficio_potencial', 3000);
});

test('sin ver todos la pantalla oculta los articulos en cero sin minimo', function () {
    $user = User::factory()->create();
    conExistencia(articuloParaInventario($user, ['modelo' => 'CON-STOCK']), 4);
    articuloParaInventario($user, ['modelo' => 'EN-CERO']);

    $modelos = collect($this->actingAs($user)->getJson('/api/v1/inventario')->json('data'))->pluck('modelo');
    expect($modelos)->toContain('CON-STOCK')->not->toContain('EN-CERO');

    $todos = collect($this->actingAs($user)->getJson('/api/v1/inventario?ver_todos=1')->json('data'))->pluck('modelo');
    expect($todos)->toContain('CON-STOCK')->toContain('EN-CERO');
});

test('ordenar por dinero invertido ordena todo el conjunto y no solo la pagina', function () {
    $user = User::factory()->create();
    conExistencia(articuloParaInventario($user, ['modelo' => 'POCO']), 1);
    conExistencia(articuloParaInventario($user, ['modelo' => 'MUCHO']), 50);

    $response = $this->actingAs($user)->getJson('/api/v1/inventario?orden=invertido&dir=desc');

    $response->assertOk()->assertJsonPath('data.0.modelo', 'MUCHO');
});

test('los umbrales se guardan sin generar movimiento', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);

    $this->actingAs($user)->putJson("/api/v1/inventario/{$articulo->id}/parametros", [
        'minimo' => 5,
        'maximo' => 20,
    ])->assertOk()->assertJsonPath('data.minimo', 5)->assertJsonPath('data.maximo', 20);

    expect($articulo->movimientosInventario()->count())->toBe(0);
});

test('el maximo no puede ser menor que el minimo', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);

    $this->actingAs($user)->putJson("/api/v1/inventario/{$articulo->id}/parametros", [
        'minimo' => 10,
        'maximo' => 4,
    ])->assertUnprocessable()->assertJsonValidationErrors('maximo');
});

// ---------------------------------------------------------------------------------------------
// Historial y auditoría
// ---------------------------------------------------------------------------------------------

test('el historial lista los movimientos del articulo con su documento origen', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);
    $orden = ordenPagadaCon($user, $articulo, [['articulo_id' => $articulo->id, 'cantidad' => 6]]);
    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")->assertOk();

    $response = $this->actingAs($user)->getJson("/api/v1/inventario/{$articulo->id}/movimientos");

    $response->assertOk();
    $response->assertJsonPath('data.0.tipo', 'entrada');
    $response->assertJsonPath('data.0.cantidad', 6);
    $response->assertJsonPath('data.0.existencia_resultante', 6);
    $response->assertJsonPath('data.0.es_automatico', true);
    $response->assertJsonPath('data.0.documento.tipo', 'orden_compra');
    $response->assertJsonPath('data.0.documento.folio', $orden->folioFormateado());
});

test('la auditoria reporta un descuadre y no lo corrige', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);

    $this->actingAs($user)->postJson("/api/v1/inventario/{$articulo->id}/ajuste", [
        'cantidad' => 10,
        'motivo' => 'conteo_fisico',
    ])->assertOk();

    // Descuadre introducido por fuera del servicio, que es justo lo que la auditoría existe para
    // detectar.
    $articulo->forceFill(['existencia' => 99])->save();

    $response = $this->actingAs($user)->getJson('/api/v1/inventario/auditoria');

    $response->assertOk();
    $response->assertJsonPath('data.0.articulo_id', $articulo->id);
    $response->assertJsonPath('data.0.existencia_guardada', 99);
    $response->assertJsonPath('data.0.existencia_calculada', 10);
    expect($articulo->refresh()->existencia)->toBe(99);
});

test('la auditoria no reporta nada cuando el historial cuadra', function () {
    $user = User::factory()->create();
    $articulo = articuloParaInventario($user);

    $this->actingAs($user)->postJson("/api/v1/inventario/{$articulo->id}/ajuste", [
        'cantidad' => 7,
        'motivo' => 'entrada_inicial',
    ])->assertOk();

    expect($this->actingAs($user)->getJson('/api/v1/inventario/auditoria')->json('data'))->toHaveCount(0);
});

// ---------------------------------------------------------------------------------------------
// Aislamiento por usuario
// ---------------------------------------------------------------------------------------------

test('no se puede ver ni mover el inventario de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $ajeno = conExistencia(articuloParaInventario($otro), 5);

    $this->actingAs($user)->getJson("/api/v1/inventario/{$ajeno->id}/movimientos")->assertNotFound();
    $this->actingAs($user)->postJson("/api/v1/inventario/{$ajeno->id}/ajuste", [
        'cantidad' => 1,
        'motivo' => 'otro',
    ])->assertNotFound();
    $this->actingAs($user)->putJson("/api/v1/inventario/{$ajeno->id}/parametros", ['minimo' => 1])->assertNotFound();

    expect($ajeno->refresh()->existencia)->toBe(5);
    expect($this->actingAs($user)->getJson('/api/v1/inventario')->json('data'))->toHaveCount(0);
});
