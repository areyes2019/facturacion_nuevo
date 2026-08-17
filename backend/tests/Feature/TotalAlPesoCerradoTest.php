<?php

use App\Enums\TipoCuenta;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\OrdenCompra;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\FacturapiService;
use PhpCfdi\Rfc\RfcFaker;

/**
 * Ajuste del total al peso cerrado (ver 030-total-al-peso-cerrado.md).
 *
 * Los casos frontera de la fórmula viven en Unit/TotalesDocumentoTest, alimentados por el fixture
 * compartido con el frontend. Aquí se verifica lo que solo se ve con la base de datos y las rutas
 * de por medio: que los tres documentos del cliente cierren en el mismo peso, que la orden de
 * compra no cierre en ninguno, que la autofactura repita el total del ticket, y que el concepto
 * del ajuste llegue al payload de facturapi.io sin traslados.
 *
 * El artículo de todas las pruebas es el del ticket que originó la historia: $175.86 sin IVA,
 * $204.00 con IVA. Tres piezas dan $611.99 de cálculo puro y $612.00 ya ajustado.
 */
function articuloDeSelloParaAjuste(User $user): Articulo
{
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    return Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Sello autoentintable 59 x 23 mm',
        'modelo' => 'MOD-5923',
        'clave_prod_serv' => '43211503',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_unitario_sin_iva' => 175.86,
        'precio_proveedor' => 100.00,
        'costo_con_descuento' => 100.00,
        'existencia' => 50,
    ]);
}

function clienteParaAjuste(User $user): Cliente
{
    return Cliente::factory()->for($user)->create([
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Comercializadora Ejemplo SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
        'correo_contacto' => 'cliente@ejemplo.com',
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function lineasDeTresSellos(Articulo $articulo): array
{
    return [[
        'articulo_id' => $articulo->id,
        'cantidad' => 3,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 175.86,
        'tasa_iva' => '16',
    ]];
}

test('la cotizacion cierra el total en peso cerrado', function () {
    $user = User::factory()->create();
    $articulo = articuloDeSelloParaAjuste($user);

    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => clienteParaAjuste($user)->id,
        'lineas' => lineasDeTresSellos($articulo),
        'total' => 612.00,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.subtotal', 527.58);
    $response->assertJsonPath('data.total_iva_16', 84.41);
    $response->assertJsonPath('data.ajuste_al_peso', 0.01);
    $response->assertJsonPath('data.total', 612);
});

test('el pedido cierra el total en peso cerrado', function () {
    $user = User::factory()->create();
    $articulo = articuloDeSelloParaAjuste($user);

    $response = $this->actingAs($user)->postJson('/api/v1/pedidos', [
        'cliente_nombre' => 'Abdias Reyes',
        'cliente_telefono' => '4613581090',
        'cliente_correo' => 'abdias@ejemplo.com',
        'lineas' => lineasDeTresSellos($articulo),
        'total' => 612.00,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.ajuste_al_peso', 0.01);
    $response->assertJsonPath('data.total', 612);
});

test('la factura cierra el total en el mismo peso que la cotizacion', function () {
    $user = User::factory()->create();
    $articulo = articuloDeSelloParaAjuste($user);
    $cliente = clienteParaAjuste($user);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn((object) [
            'id' => 'inv_test_612',
            'uuid' => '8ff503a2-c6b7-4a25-9999-a25610e6b488',
            'series' => 'F',
            'folio_number' => 1,
            'cfdi_version' => 4,
            'stamp' => (object) [
                'signature' => 'SELLO_CFDI_DE_PRUEBA',
                'sat_signature' => 'SELLO_SAT_DE_PRUEBA',
                'sat_cert_number' => '20001000000300022323',
                'date' => '2026-08-17T14:08:00',
                'complement_string' => '||1.1||',
            ],
        ]);
    });

    $response = $this->actingAs($user)->postJson('/api/v1/facturas', [
        'cliente_id' => $cliente->id,
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => lineasDeTresSellos($articulo),
        'total' => 612.00,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.ajuste_al_peso', 0.01);
    $response->assertJsonPath('data.total', 612);
    $this->assertDatabaseHas('facturas', ['user_id' => $user->id, 'total' => 612.00]);
});

test('la orden de compra paga lo que cobra el proveedor y no redondea', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 0]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Goma para sello',
        'modelo' => 'MOD-5923',
        'precio_proveedor' => 175.86,
        'costo_con_descuento' => 175.86,
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/ordenes-compra', [
        'proveedor_id' => $proveedor->id,
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 3,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 175.86,
            'tasa_iva' => '16',
        ]],
        'total' => 611.99,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.total', 611.99);
    $response->assertJsonMissingPath('data.ajuste_al_peso');
    expect(OrdenCompra::latest('id')->firstOrFail()->total)->toBe('611.99');
});

test('la autofactura repite el total del ticket, ajuste incluido', function () {
    $user = User::factory()->create();
    $articulo = articuloDeSelloParaAjuste($user);

    $cuenta = Cuenta::factory()->for($user)->create([
        'nombre' => 'Caja General',
        'tipo' => TipoCuenta::Efectivo->value,
        'saldo_inicial' => 0,
        'saldo_actual' => 0,
        'activa' => true,
    ]);

    $this->actingAs($user)->postJson('/api/v1/pedidos', [
        'cliente_nombre' => 'Abdias Reyes',
        'cliente_telefono' => '4613581090',
        'cliente_correo' => 'abdias@ejemplo.com',
        'lineas' => lineasDeTresSellos($articulo),
        'total' => 612.00,
    ])->assertCreated();

    $pedido = Pedido::where('user_id', $user->id)->latest('id')->firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 612.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $pedido = $pedido->fresh();

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn((object) [
            'id' => 'inv_test_auto',
            'uuid' => '8ff503a2-c6b7-4a25-9999-a25610e6b489',
            'series' => 'F',
            'folio_number' => 2,
            'cfdi_version' => 4,
            'stamp' => (object) [
                'signature' => 'SELLO_CFDI_DE_PRUEBA',
                'sat_signature' => 'SELLO_SAT_DE_PRUEBA',
                'sat_cert_number' => '20001000000300022323',
                'date' => '2026-08-17T14:20:00',
                'complement_string' => '||1.1||',
            ],
        ]);
    });

    $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", [
        'rfc' => 'XAXX010101000',
        'razon_social' => 'PUBLICO EN GENERAL',
        'regimen_fiscal' => '616',
        'codigo_postal_fiscal' => '20000',
        'correo' => 'abdias@ejemplo.com',
        'uso_cfdi' => 'S01',
    ])->assertOk();

    $factura = Factura::where('user_id', $user->id)->latest('id')->firstOrFail();

    expect((float) $factura->ajuste_al_peso)->toBe((float) $pedido->ajuste_al_peso);
    expect((float) $factura->total)->toBe((float) $pedido->total);
    expect((float) $factura->total)->toBe(612.00);
});

test('el payload de facturapi lleva el ajuste como concepto sin traslados', function () {
    $user = User::factory()->create();
    $articulo = articuloDeSelloParaAjuste($user);
    $cliente = clienteParaAjuste($user);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn((object) [
            'id' => 'inv_test_612',
            'uuid' => '8ff503a2-c6b7-4a25-9999-a25610e6b488',
            'series' => 'F',
            'folio_number' => 1,
            'cfdi_version' => 4,
            'stamp' => (object) [
                'signature' => 'SELLO_CFDI_DE_PRUEBA',
                'sat_signature' => 'SELLO_SAT_DE_PRUEBA',
                'sat_cert_number' => '20001000000300022323',
                'date' => '2026-08-17T14:08:00',
                'complement_string' => '||1.1||',
            ],
        ]);
    });

    $this->actingAs($user)->postJson('/api/v1/facturas', [
        'cliente_id' => $cliente->id,
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => lineasDeTresSellos($articulo),
        'total' => 612.00,
    ])->assertCreated();

    $factura = Factura::where('user_id', $user->id)->latest('id')->firstOrFail();
    $factura->load(['cliente', 'lineas.articulo']);

    $payload = payloadDeFactura($factura);
    $items = $payload['items'];

    expect($items)->toHaveCount(2);

    $ajuste = $items[1];
    expect($ajuste['quantity'])->toBe(1);
    expect($ajuste['product']['description'])->toBe('Ajuste al peso');
    expect($ajuste['product']['price'])->toBe(0.01);
    expect($ajuste['product']['taxes'])->toBe([]);
    expect($ajuste['product']['unit_key'])->toBe('ACT');

    // La suma de lo que se le manda a facturapi.io tiene que dar el total guardado: importe de la
    // línea, su IVA, y el ajuste sin impuesto encima.
    $importeLinea = $items[0]['quantity'] * $items[0]['product']['price'];
    expect(round($importeLinea * 1.16 + $ajuste['product']['price'], 2))->toBe(612.00);
});

test('sin ajuste el payload no lleva el concepto extra', function () {
    $user = User::factory()->create();
    $articulo = articuloDeSelloParaAjuste($user);
    $cliente = clienteParaAjuste($user);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn((object) [
            'id' => 'inv_test_234',
            'uuid' => '8ff503a2-c6b7-4a25-9999-a25610e6b487',
            'series' => 'F',
            'folio_number' => 3,
            'cfdi_version' => 4,
            'stamp' => (object) [
                'signature' => 'SELLO_CFDI_DE_PRUEBA',
                'sat_signature' => 'SELLO_SAT_DE_PRUEBA',
                'sat_cert_number' => '20001000000300022323',
                'date' => '2026-08-17T14:08:00',
                'complement_string' => '||1.1||',
            ],
        ]);
    });

    // Una sola pieza: $201.72 + IVA da $234.00 exacto y no hay nada que ajustar.
    $this->actingAs($user)->postJson('/api/v1/facturas', [
        'cliente_id' => $cliente->id,
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 1,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 201.72,
            'tasa_iva' => '16',
        ]],
        'total' => 234.00,
    ])->assertCreated();

    $factura = Factura::where('user_id', $user->id)->latest('id')->firstOrFail();
    $factura->load(['cliente', 'lineas.articulo']);

    expect((float) $factura->ajuste_al_peso)->toBe(0.0);
    expect(payloadDeFactura($factura)['items'])->toHaveCount(1);
});

/**
 * El payload es privado porque nadie fuera del servicio tiene por qué armarlo; se lee por reflexión
 * para poder verificar lo que se le manda a facturapi.io sin salir a la red.
 *
 * @return array<string, mixed>
 */
function payloadDeFactura(Factura $factura): array
{
    $servicio = new FacturapiService;
    $metodo = new ReflectionMethod($servicio, 'construirPayloadFactura');

    return $metodo->invoke($servicio, $factura);
}
