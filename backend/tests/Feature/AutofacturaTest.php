<?php

use App\Enums\TipoCuenta;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\FacturapiService;
use Facturapi\Exceptions\FacturapiException;
use Illuminate\Support\Facades\Mail;

/**
 * Portal público de autofacturación (ver 027-venta-mostrador-ticket.md).
 *
 * Las pruebas mockean `FacturapiService` porque no hay credenciales reales en este entorno, mismo
 * criterio que 007 y 008.
 */
function pedidoParaAutofactura(User $user, array $overridesLinea = []): Pedido
{
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Sello automático 40x15',
        'modelo' => 'MOD-1234',
        'clave_prod_serv' => '43211503',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_unitario_sin_iva' => 100.00,
    ]);

    Existencia::factory()->create(['articulo_id' => $articulo->id, 'existencia' => 10]);

    $cuenta = Cuenta::factory()->for($user)->create([
        'nombre' => 'Caja General',
        'tipo' => TipoCuenta::Efectivo->value,
        'saldo_inicial' => 0,
        'saldo_actual' => 0,
        'activa' => true,
    ]);

    $linea = array_merge([
        'articulo_id' => $articulo->id,
        'cantidad' => 2,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 100.00,
        'tasa_iva' => '16',
    ], $overridesLinea);

    test()->actingAs($user)->postJson('/api/v1/pedidos', [
        'cliente_nombre' => 'María Pérez',
        'cliente_telefono' => '5512345678',
        'cliente_correo' => 'maria@ejemplo.com',
        'lineas' => [$linea],
        'total' => 232.00,
    ])->assertCreated();

    $pedido = Pedido::where('user_id', $user->id)->latest('id')->firstOrFail();

    test()->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 232.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    return $pedido->fresh();
}

function datosFiscalesValidos(array $overrides = []): array
{
    return array_merge([
        'rfc' => 'XAXX010101000',
        'razon_social' => 'PUBLICO EN GENERAL',
        'regimen_fiscal' => '616',
        'codigo_postal_fiscal' => '20000',
        'uso_cfdi' => 'S01',
        'correo' => 'maria@ejemplo.com',
    ], $overrides);
}

function respuestaTimbradoFalsa(): object
{
    return (object) [
        'id' => 'facturapi_123',
        'uuid' => 'ABCD1234-5678-90AB-CDEF-1234567890AB',
        'series' => 'A',
        'folio_number' => 1,
        'stamp' => (object) [
            'signature' => 'firma-del-cfdi-1234',
            'sat_signature' => 'firma-del-sat',
            'complement_string' => 'cadena-original',
            'sat_cert_number' => '00001000000500000001',
            'date' => now()->toIso8601String(),
        ],
        'cfdi_version' => '4.0',
    ];
}

test('el enlace de autofactura funciona sin sesion y describe la compra', function () {
    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user);

    $response = $this->getJson("/api/v1/autofactura/{$pedido->autofactura_token}");

    $response->assertOk();
    $response->assertJsonPath('numero_ticket', '00001');
    $response->assertJsonPath('total', 232);
    $response->assertJsonPath('no_disponible', null);
    // Es una página abierta: nunca expone el teléfono ni las líneas del pedido.
    $response->assertJsonMissingPath('cliente_telefono');
    $response->assertJsonMissingPath('lineas');
});

test('un token inexistente responde 404 sin distinguirse de ningun otro caso', function () {
    $this->getJson('/api/v1/autofactura/'.str_repeat('a', 64))->assertNotFound();
});

test('un pedido con saldo pendiente no tiene enlace de autofactura', function () {
    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user);

    $pago = $pedido->pagos()->firstOrFail();
    $this->actingAs($user)->deleteJson("/api/v1/pedidos/{$pedido->id}/pagos/{$pago->id}")->assertNoContent();

    $response = $this->getJson("/api/v1/autofactura/{$pedido->autofactura_token}");

    $response->assertOk();
    expect($response->json('no_disponible'))->toContain('saldo pendiente');
});

test('vencido el mes de la venta el enlace deja de servir', function () {
    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user);

    Pedido::where('id', $pedido->id)->update(['created_at' => now()->subMonths(2)]);

    $response = $this->getJson("/api/v1/autofactura/{$pedido->autofactura_token}");

    expect($response->json('no_disponible'))->toContain('plazo');

    $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", datosFiscalesValidos())
        ->assertUnprocessable();
});

test('autofacturar crea el cliente fiscal timbra y vincula el pedido', function () {
    Mail::fake();
    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(respuestaTimbradoFalsa());
        $mock->shouldReceive('descargarXml')->andReturn('<xml/>');
    });

    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user);

    $response = $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", datosFiscalesValidos());

    $response->assertOk();
    $response->assertJsonPath('timbrada', true);

    $factura = Factura::where('user_id', $user->id)->firstOrFail();
    expect($factura->estado->value)->toBe('timbrada');
    // PUE siempre: el enlace solo existe con el pedido totalmente pagado.
    expect($factura->metodo_pago->value)->toBe('PUE');
    // Efectivo en la caja → 01 del catálogo del SAT.
    expect($factura->forma_pago)->toBe('01');

    expect(Pedido::find($pedido->id)->factura_id)->toBe($factura->id);
    $this->assertDatabaseHas('clientes', ['user_id' => $user->id, 'rfc' => 'XAXX010101000']);
});

test('la factura de autofactura no descuenta inventario por segunda vez', function () {
    Mail::fake();
    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(respuestaTimbradoFalsa());
        $mock->shouldReceive('descargarXml')->andReturn('<xml/>');
    });

    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user);
    $articulo = Articulo::where('user_id', $user->id)->firstOrFail();

    // El pedido ya descontó 2 de 10 al levantarse en el mostrador.
    expect(Existencia::where('articulo_id', $articulo->id)->first()->existencia)->toBe(8);

    $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", datosFiscalesValidos())->assertOk();

    expect(Existencia::where('articulo_id', $articulo->id)->first()->existencia)->toBe(8);
});

test('una linea libre se timbra con las claves genericas del SAT', function () {
    Mail::fake();
    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(respuestaTimbradoFalsa());
        $mock->shouldReceive('descargarXml')->andReturn('<xml/>');
    });

    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user, [
        'articulo_id' => null,
        'descripcion' => 'Grabado especial a mano',
        'modelo' => null,
    ]);

    $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", datosFiscalesValidos())->assertOk();

    $factura = Factura::where('user_id', $user->id)->firstOrFail();
    expect($factura->lineas()->firstOrFail()->articulo_id)->toBeNull();
});

test('un timbrado fallido explica el motivo en espanol y no consume el enlace', function () {
    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andThrow(new FacturapiException('The zip code does not match the tax_id'));
    });

    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user);

    $response = $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", datosFiscalesValidos());

    $response->assertUnprocessable();
    expect($response->json('message'))->toContain('código postal');

    $recargado = Pedido::find($pedido->id);
    // Ni factura a medias ni pedido marcado como facturado: la transacción se revirtió entera.
    expect($recargado->factura_id)->toBeNull();
    expect(Factura::where('user_id', $user->id)->count())->toBe(0);
    // El token sigue vivo para que el cliente corrija y reintente.
    expect($recargado->autofactura_token)->toBe($pedido->autofactura_token);
    // Y el usuario se entera desde su listado.
    expect($recargado->autofactura_error)->not->toBeNull();
});

test('un pedido ya facturado rechaza un segundo intento', function () {
    Mail::fake();
    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(respuestaTimbradoFalsa());
        $mock->shouldReceive('descargarXml')->andReturn('<xml/>');
    });

    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user);

    $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", datosFiscalesValidos())->assertOk();
    $segundo = $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", datosFiscalesValidos());

    $segundo->assertUnprocessable();
    expect($segundo->json('message'))->toContain('ya fue facturado');
});

test('el RFC mal formado se rechaza antes de llegar al SAT', function () {
    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user);

    $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", datosFiscalesValidos(['rfc' => 'NO-ES-RFC']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rfc');
});

test('si el RFC ya existe en el catalogo se reusa esa ficha', function () {
    Mail::fake();
    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andReturn(respuestaTimbradoFalsa());
        $mock->shouldReceive('descargarXml')->andReturn('<xml/>');
    });

    $user = User::factory()->create();
    $pedido = pedidoParaAutofactura($user);
    Cliente::factory()->for($user)->create([
        'rfc' => 'XAXX010101000',
        'razon_social' => 'PUBLICO EN GENERAL',
        'regimen_fiscal' => '616',
        'codigo_postal_fiscal' => '20000',
    ]);

    $this->postJson("/api/v1/autofactura/{$pedido->autofactura_token}", datosFiscalesValidos())->assertOk();

    expect(Cliente::where('user_id', $user->id)->where('rfc', 'XAXX010101000')->count())->toBe(1);
});
