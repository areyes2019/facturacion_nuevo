<?php

use App\Enums\EstadoCancelacion;
use App\Enums\EstadoFactura;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\FacturapiService;
use Facturapi\Exceptions\FacturapiException;
use PhpCfdi\Rfc\RfcFaker;

function crearClienteYArticulo(User $user, array $overridesArticulo = []): array
{
    $cliente = Cliente::factory()->for($user)->create([
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Comercializadora Ejemplo SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
    ]);

    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(array_merge([
        'nombre' => 'Laptop 14 pulgadas',
        'modelo' => 'MOD-1234',
        'clave_prod_serv' => '43211503',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_unitario_sin_iva' => 100.00,
    ], $overridesArticulo));

    return [$cliente, $articulo];
}

function datosFacturaValidos(Cliente $cliente, Articulo $articulo, array $overrides = []): array
{
    return array_merge([
        'cliente_id' => $cliente->id,
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => [
            [
                'articulo_id' => $articulo->id,
                'cantidad' => 2,
                'descripcion' => $articulo->nombre,
                'modelo' => $articulo->modelo,
                'precio_unitario' => 100.00,
                'tasa_iva' => '16',
            ],
        ],
        'total' => 232.00,
    ], $overrides);
}

function respuestaTimbradoExitosa(): object
{
    return (object) [
        'id' => 'inv_test_123',
        'uuid' => '8ff503a2-c6b7-4a25-9999-a25610e6b488',
        'series' => 'F',
        'folio_number' => 1433,
        'cfdi_version' => 4,
        'stamp' => (object) [
            'signature' => 'SELLO_CFDI_DE_PRUEBA',
            'sat_signature' => 'SELLO_SAT_DE_PRUEBA',
            'sat_cert_number' => '20001000000300022323',
            'date' => '2026-07-31T16:07:08',
            'complement_string' => '||1.1|8ff503a2-c6b7-4a25-9999-a25610e6b488|2026-07-31T16:07:08|SELLO_CFDI_DE_PRUEBA|20001000000300022323||',
        ],
    ];
}

test('un invitado no puede acceder a facturas', function () {
    $this->getJson('/api/v1/facturas')->assertUnauthorized();
});

test('crear una factura la timbra de inmediato cuando facturapi responde exito', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = crearClienteYArticulo($user);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(respuestaTimbradoExitosa());
    });

    $response = $this->actingAs($user)->postJson('/api/v1/facturas', datosFacturaValidos($cliente, $articulo));

    $response->assertCreated();
    $response->assertJsonPath('data.estado', 'timbrada');
    $response->assertJsonPath('data.uuid_fiscal', '8ff503a2-c6b7-4a25-9999-a25610e6b488');
    $response->assertJsonPath(
        'data.cadena_original_sat',
        '||1.1|8ff503a2-c6b7-4a25-9999-a25610e6b488|2026-07-31T16:07:08|SELLO_CFDI_DE_PRUEBA|20001000000300022323||',
    );
    $response->assertJsonPath('data.subtotal', 200);
    $response->assertJsonPath('data.total_iva_16', 32);
    $response->assertJsonPath('data.total', 232);
    $this->assertDatabaseHas('facturas', [
        'user_id' => $user->id,
        'estado' => 'timbrada',
        'folio' => 1,
    ]);
    $this->assertDatabaseHas('factura_lineas', [
        'descripcion' => 'Laptop 14 pulgadas',
        'importe' => 200.00,
        'iva_importe' => 32.00,
    ]);
});

test('si facturapi falla la factura queda pendiente con el error, sin perder los datos', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = crearClienteYArticulo($user);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andThrow(new FacturapiException('RFC del receptor inválido'));
    });

    $response = $this->actingAs($user)->postJson('/api/v1/facturas', datosFacturaValidos($cliente, $articulo));

    $response->assertCreated();
    $response->assertJsonPath('data.estado', 'pendiente');
    $response->assertJsonPath('data.error_timbrado', 'RFC del receptor inválido');
    $this->assertDatabaseHas('facturas', [
        'user_id' => $user->id,
        'estado' => 'pendiente',
    ]);
    $this->assertDatabaseHas('factura_lineas', [
        'descripcion' => 'Laptop 14 pulgadas',
    ]);
});

test('si el total no coincide con lo calculado la peticion se rechaza', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = crearClienteYArticulo($user);

    $response = $this->actingAs($user)->postJson('/api/v1/facturas', datosFacturaValidos($cliente, $articulo, [
        'total' => 999.00,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('total');
});

test('no se puede facturar un cliente ajeno', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    [, $articulo] = crearClienteYArticulo($user);
    [$clienteAjeno] = crearClienteYArticulo($otro);

    $response = $this->actingAs($user)->postJson('/api/v1/facturas', datosFacturaValidos($clienteAjeno, $articulo));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('cliente_id');
});

test('una factura timbrada no puede editarse ni eliminarse', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticulo($user);
    $factura = Factura::factory()->for($user)->for($cliente)->create(['estado' => EstadoFactura::Timbrada->value]);

    $this->actingAs($user)->putJson("/api/v1/facturas/{$factura->id}", [])->assertStatus(422);
    $this->actingAs($user)->deleteJson("/api/v1/facturas/{$factura->id}")->assertStatus(422);
});

test('cancelar una factura timbrada la marca cancelada cuando facturapi confirma accepted', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticulo($user);
    $factura = Factura::factory()->for($user)->for($cliente)->create([
        'estado' => EstadoFactura::Timbrada->value,
        'facturapi_invoice_id' => 'inv_test_123',
        'uuid_fiscal' => '8ff503a2-c6b7-4a25-9999-a25610e6b488',
    ]);

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('cancelarFactura')->once()->andReturn((object) ['cancellation_status' => EstadoCancelacion::Accepted->value]);
    });

    $response = $this->actingAs($user)->postJson("/api/v1/facturas/{$factura->id}/cancelar", [
        'motivo_cancelacion' => '02',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'cancelada');
    $response->assertJsonPath('data.estado_cancelacion', 'accepted');
});

test('el listado de facturas solo muestra las del usuario autenticado', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    [$clienteUser] = crearClienteYArticulo($user);
    [$clienteOtro] = crearClienteYArticulo($otro);

    Factura::factory()->for($user)->for($clienteUser)->create();
    Factura::factory()->for($otro)->for($clienteOtro)->create();

    $response = $this->actingAs($user)->getJson('/api/v1/facturas');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
});
