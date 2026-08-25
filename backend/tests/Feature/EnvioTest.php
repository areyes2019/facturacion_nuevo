<?php

use App\Enums\EstadoOrdenTrabajo;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\User;

/**
 * Producción: envío a domicilio de una Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md).
 */
function envioArticulo(User $user): Articulo
{
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    return Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Sello personalizado',
        'precio_unitario_sin_iva' => 100.00,
        'existencia' => 10,
    ]);
}

/** Orden de Trabajo ya en "Listo para entregar", lista para probar el envío. */
function envioOrdenLista(User $user): OrdenTrabajo
{
    $articulo = envioArticulo($user);
    $cuenta = Cuenta::factory()->for($user)->create();

    test()->actingAs($user)->postJson('/api/v1/pedidos', [
        'cliente_nombre' => 'Juan Pérez',
        'cliente_telefono' => '5512345678',
        'cliente_correo' => null,
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 2,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 232.00,
    ])->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->latest('id')->firstOrFail();

    test()->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 232.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    test()->actingAs($user)->postJson('/api/v1/ordenes-trabajo', [
        'documentable_type' => 'pedido',
        'documentable_id' => $pedido->id,
    ])->assertCreated();
    $orden = OrdenTrabajo::where('documentable_id', $pedido->id)->firstOrFail();

    test()->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/iniciar-produccion")->assertOk();
    test()->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/marcar-listo")->assertOk();

    return $orden->fresh();
}

function datosEnvioValidos(array $overrides = []): array
{
    return array_merge([
        'nombre_receptor' => 'Ana López',
        'telefono_receptor' => '5599998888',
        'direccion' => 'Av. Reforma 123, Col. Centro',
        'fecha_recepcion' => now()->toDateString(),
        'hora_recepcion' => '15:30',
        'tarifa' => 'b',
        'forma_pago' => 'por_cobrar',
    ], $overrides);
}

test('no se puede enviar a domicilio una orden que no esta lista para entregar', function () {
    $user = User::factory()->create();
    $articulo = envioArticulo($user);
    $cuenta = Cuenta::factory()->for($user)->create();

    $this->actingAs($user)->postJson('/api/v1/pedidos', [
        'cliente_nombre' => 'Juan Pérez',
        'cliente_telefono' => '5512345678',
        'cliente_correo' => null,
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 1,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 116.00,
    ])->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();
    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 116.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/ordenes-trabajo', [
        'documentable_type' => 'pedido',
        'documentable_id' => $pedido->id,
    ])->assertCreated();
    $orden = OrdenTrabajo::where('documentable_id', $pedido->id)->firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/envio", datosEnvioValidos())
        ->assertStatus(422);
});

test('un envio por cobrar no genera ningun movimiento de tesoreria y deja la orden a domicilio', function () {
    $user = User::factory()->create();
    $orden = envioOrdenLista($user);
    $movimientosPrevios = Movimiento::count();

    $response = $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/envio", datosEnvioValidos([
        'forma_pago' => 'por_cobrar',
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'a_domicilio');
    $response->assertJsonPath('data.envio.forma_pago', 'por_cobrar');
    $response->assertJsonPath('data.envio.monto', 80);

    $this->assertDatabaseHas('envios', [
        'documentable_type' => OrdenTrabajo::class,
        'documentable_id' => $orden->id,
        'forma_pago' => 'por_cobrar',
    ]);
    // El pago del pedido ya generó su propio movimiento; el envío por cobrar no debe sumar otro.
    expect(Movimiento::count())->toBe($movimientosPrevios);
});

test('un envio prepagado genera un movimiento de ingreso en la cuenta elegida', function () {
    $user = User::factory()->create();
    $orden = envioOrdenLista($user);
    $cuenta = Cuenta::factory()->for($user)->create(['saldo_inicial' => 0, 'saldo_actual' => 0]);
    $movimientosPrevios = Movimiento::count();

    $response = $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/envio", datosEnvioValidos([
        'forma_pago' => 'prepagado',
        'tarifa' => 'c',
        'cuenta_id' => $cuenta->id,
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.envio.monto', 120);

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(120.0);
    expect(Movimiento::count())->toBe($movimientosPrevios + 1);
});

test('un envio prepagado sin cuenta se rechaza', function () {
    $user = User::factory()->create();
    $orden = envioOrdenLista($user);

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/envio", datosEnvioValidos([
        'forma_pago' => 'prepagado',
    ]))->assertUnprocessable()->assertJsonValidationErrors('cuenta_id');
});

test('un envio por cobrar con cuenta se rechaza', function () {
    $user = User::factory()->create();
    $orden = envioOrdenLista($user);
    $cuenta = Cuenta::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/envio", datosEnvioValidos([
        'forma_pago' => 'por_cobrar',
        'cuenta_id' => $cuenta->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('cuenta_id');
});

test('cambiar el monto configurado de una tarifa no altera los envios ya creados', function () {
    $user = User::factory()->create();
    $orden = envioOrdenLista($user);

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/envio", datosEnvioValidos(['tarifa' => 'a']))
        ->assertOk()
        ->assertJsonPath('data.envio.monto', 50);

    $this->actingAs($user)->putJson('/api/v1/configuracion', ['envio_tarifa_a' => '999.00'])->assertOk();

    expect((float) $orden->fresh()->envio->monto)->toBe(50.0);
});

test('un envio sin direccion se rechaza', function () {
    $user = User::factory()->create();
    $orden = envioOrdenLista($user);

    $datos = datosEnvioValidos();
    unset($datos['direccion']);

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/envio", $datos)
        ->assertUnprocessable()->assertJsonValidationErrors('direccion');
});

test('marcar entregada una orden a domicilio', function () {
    $user = User::factory()->create();
    $orden = envioOrdenLista($user);
    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/envio", datosEnvioValidos())->assertOk();

    $response = $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/entregar");

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'entregado');
    expect($orden->fresh()->estado)->toBe(EstadoOrdenTrabajo::Entregado);
});
